<?php

namespace Modules\Articoli;

use Common\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules;
use Modules\Interventi\Components\Articolo as ArticoloIntervento;
use Modules\Iva\Aliquota;
use Plugins\FornitoriArticolo\Dettaglio;
use Traits\RecordTrait;
use Uploads;

class Articolo extends Model
{
    use SoftDeletes;
    use RecordTrait;

    protected $guarded = [
        'qta',
    ];

    protected $table = 'mg_articoli';

    public static function build($codice, $nome, Categoria $categoria = null, Categoria $sottocategoria = null)
    {
        $model = parent::build();

        $model->codice = $codice;
        $model->descrizione = $nome;

        $model->abilita_serial = false;
        $model->attivo = true;

        $model->categoria()->associate($categoria);
        $model->sottocategoria()->associate($sottocategoria);

        $model->save();

        return $model;
    }

    /**
     * Funzione per registrare un movimento del magazzino in relazione all'articolo corrente, modificando di conseguenza la quantità dell'articolo stesso.
     *
     * @param $qta
     * @param string $descrizone
     * @param string $data
     * @param bool   $manuale
     * @param array  $array
     *
     * @return bool
     */
    public function movimenta($qta, $descrizone = null, $data = null, $manuale = false, $array = [])
    {
        $id = $this->registra($qta, $descrizone, $data, $manuale, $array);

        if ($this->servizio == 0) {
            $this->qta += $qta;

            $this->save();
        }

        return $id;
    }

    /**
     * Funzione per registrare un movimento del magazzino in relazione all'articolo corrente, senza movimentare la quantità dell'articolo stesso.
     *
     * @param $qta
     * @param string $descrizone
     * @param string $data
     * @param bool   $manuale
     * @param array  $array
     *
     * @return bool
     */
    public function registra($qta, $descrizone = null, $data = null, $manuale = false, $array = [])
    {
        if (empty($qta)) {
            return false;
        }

        // Movimento il magazzino solo se l'articolo non è un servizio
        if ($this->servizio == 0) {
            // Registrazione della movimentazione
            database()->insert('mg_movimenti', array_merge($array, [
                'idarticolo' => $this->id,
                'qta' => $qta,
                'movimento' => $descrizone,
                'data' => $data,
                'manuale' => $manuale,
            ]));
        }
        $id = database()->lastInsertedID();

        return $id;
    }

    /**
     * Imposta il prezzo di vendita sulla base dell'impstazione per l'utilizzo dei prezzi comprensivi di IVA.
     *
     * @param $prezzo_vendita
     * @param $id_iva
     */
    public function setPrezzoVendita($prezzo_vendita, $id_iva)
    {
        $this->idiva_vendita = $id_iva;

        // Calcolo prezzo di vendita ivato e non ivato
        $prezzi_ivati = setting('Utilizza prezzi di vendita comprensivi di IVA');
        $percentuale_aliquota = floatval(Aliquota::find($id_iva)->percentuale);
        if ($prezzi_ivati) {
            $this->prezzo_vendita_ivato = $prezzo_vendita;
            $this->prezzo_vendita = $prezzo_vendita / (1 + $percentuale_aliquota / 100);
        } else {
            $this->prezzo_vendita = $prezzo_vendita;
            $this->prezzo_vendita_ivato = $prezzo_vendita * (1 + $percentuale_aliquota / 100);
        }
    }

    // Attributi Eloquent

    public function getImmagineUploadAttribute()
    {
        if (empty($this->immagine)) {
            return null;
        }

        return $this->uploads()->where('filename', $this->immagine)->first();
    }

    public function getImageAttribute()
    {
        if (empty($this->immagine)) {
            return null;
        }

        $module = Modules::get($this->module);
        $fileinfo = Uploads::fileInfo($this->immagine);

        $directory = '/'.$module->upload_directory.'/';
        $image = $directory.$this->immagine;
        $image_thumbnail = $directory.$fileinfo['filename'].'_thumb600.'.$fileinfo['extension'];

        $url = file_exists(DOCROOT.$image_thumbnail) ? ROOTDIR.$image_thumbnail : ROOTDIR.$image;

        return $url;
    }

    /**
     * Restituisce il nome del modulo a cui l'oggetto è collegato.
     *
     * @return string
     */
    public function getModuleAttribute()
    {
        return 'Articoli';
    }

    // Relazioni Eloquent

    public function articoli()
    {
        return $this->hasMany(ArticoloIntervento::class, 'idarticolo');
    }

    /**
     * Restituisce i movimenti di magazzino dell'articolo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Query\Builder
     */
    public function movimenti()
    {
        return $this->hasMany(Movimento::class, 'idarticolo');
    }

    /**
     * Restituisce i movimenti di magazzino dell'articolo raggruppati per documento relativo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Query\Builder
     */
    public function movimentiComposti()
    {
        return $this->movimenti()
            ->selectRaw('*, sum(qta) as qta_documento, IFNULL(reference_type, UUID()) as tipo_gruppo')
            ->groupBy('tipo_gruppo', 'reference_id');
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'id_categoria');
    }

    public function sottocategoria()
    {
        return $this->belongsTo(Categoria::class, 'id_sottocategoria');
    }

    public function dettaglioFornitori()
    {
        return $this->hasMany(Dettaglio::class, 'id_articolo');
    }

    public function dettaglioFornitore($id_fornitore)
    {
        return $this->dettaglioFornitori()
            ->where('id_fornitore', $id_fornitore)
            ->first();
    }
}
