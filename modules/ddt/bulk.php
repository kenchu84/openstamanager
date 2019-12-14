<?php

use Modules\Anagrafiche\Anagrafica;
use Modules\Fatture\Fattura;
use Modules\Fatture\Tipo;

if ($module['name'] == 'Ddt di vendita') {
    $dir = 'entrata';
    $module_name = 'Fatture di vendita';
} else {
    $dir = 'uscita';
    $module_name = 'Fatture di acquisto';
}

// Segmenti
$modulo = \Modules\Module::get($module_name);
$id_fatture = $modulo['id'];
if (!isset($_SESSION['module_'.$id_fatture]['id_segment'])) {
    $segments = $modulo->getSegments();
    $_SESSION['module_'.$id_fatture]['id_segment'] = isset($segments[0]['id']) ? $segments[0]['id'] : null;
}
$id_segment = $_SESSION['module_'.$id_fatture]['id_segment'];

$additionals = \Modules\Module::get($id_module)->getAdditionalsQuery();

switch (post('op')) {
    case 'crea_fattura':
        $id_documento_cliente = [];
        $totale_n_ddt = 0;

        // Informazioni della fattura
        if ($dir == 'entrata') {
            $tipo_documento = 'Fattura immediata di vendita';
        } else {
            $tipo_documento = 'Fattura immediata di acquisto';
        }

        $tipo_documento = Tipo::where('descrizione', $tipo_documento)->first();

        $idiva = setting('Iva predefinita');
        $data = date('Y-m-d');
        $id_segment = post('id_segment');

        // Lettura righe selezionate
        foreach ($id_records as $id) {
            $id_anagrafica = $dbo->selectOne('dt_ddt', 'idanagrafica', ['id' => $id])['idanagrafica'];

            $righe = $dbo->fetchArray('SELECT * FROM dt_righe_ddt WHERE idddt='.prepare($id).' AND idddt NOT IN (SELECT idddt FROM co_righe_documenti WHERE idddt IS NOT NULL)');

            // Proseguo solo se i ddt scelti sono fatturabili
            if (!empty($righe)) {
                $id_documento = $id_documento_cliente[$id_anagrafica];
                ++$totale_n_ddt;

                // Se non c'è già una fattura appena creata per questo cliente, creo una fattura nuova
                if (empty($id_documento)) {
                    $anagrafica = Anagrafica::find($id_anagrafica);
                    $fattura = Fattura::build($anagrafica, $tipo_documento, $data, $id_segment);

                    $id_documento = $fattura->id;
                    $id_documento_cliente[$id_anagrafica] = $id_documento;
                }

                // Inserimento righe
                foreach ($righe as $riga) {
                    $qta = $riga['qta'] - $riga['qta_evasa'];

                    if ($qta > 0) {
                        $dbo->insert('co_righe_documenti', [
                            'iddocumento' => $id_documento,
                            'idarticolo' => $riga['idarticolo'],
                            'idddt' => $id,
                            'idiva' => $riga['idiva'],
                            'desc_iva' => $riga['desc_iva'],
                            'iva' => $riga['iva'],
                            'iva_indetraibile' => $riga['iva_indetraibile'],
                            'descrizione' => $riga['descrizione'],
                            'is_descrizione' => $riga['is_descrizione'],
                            'subtotale' => $riga['subtotale'],
                            'sconto' => $riga['sconto'],
                            'sconto_unitario' => $riga['sconto_unitario'],
                            'tipo_sconto' => $riga['tipo_sconto'],
                            'um' => $riga['um'],
                            'qta' => $qta,
                            'abilita_serial' => $riga['abilita_serial'],
                            'order' => orderValue('co_righe_documenti', 'iddocumento', $id_documento),
                        ]);
                        $id_riga_documento = $dbo->lastInsertedID();

                        // Copia dei serial tra le righe
                        if (!empty($riga['idarticolo'])) {
                            $dbo->query('INSERT INTO mg_prodotti (id_riga_documento, id_articolo, dir, serial, lotto, altro) SELECT '.prepare($id_riga_documento).', '.prepare($riga['idarticolo']).', '.prepare($dir).', serial, lotto, altro FROM mg_prodotti AS t WHERE id_riga_ddt='.prepare($riga['id']));
                        }

                        // Aggiorno la quantità evasa
                        $dbo->query('UPDATE dt_righe_ddt SET qta_evasa = qta WHERE id='.prepare($riga['id']));

                        // Aggiorno lo stato ddt
                        $dbo->query('UPDATE dt_ddt SET id_stato = (SELECT id FROM dt_statiddt WHERE descrizione="Fatturato") WHERE id='.prepare($id));
                    }

                    // Ricalcolo inps, ritenuta e bollo
                    ricalcola_costiagg_fattura($id_documento);
                }
            }
        }

        if ($totale_n_ddt > 0) {
            flash()->info(tr('_NUM_ ddt fatturati!', [
                '_NUM_' => $totale_n_ddt,
            ]));
        } else {
            flash()->warning(tr('Nessun ddt fatturato!'));
        }

    break;

    case 'delete-bulk':

        foreach ($id_records as $id) {
            $dbo->query('DELETE  FROM dt_ddt  WHERE id = '.prepare($id).$additionals);
            $dbo->query('DELETE FROM dt_righe_ddt WHERE idddt='.prepare($id).$additionals);
            $dbo->query('DELETE FROM mg_movimenti WHERE idddt='.prepare($id).$additionals);
        }

        flash()->info(tr('Ddt eliminati!'));

    break;
}

if (App::debug()) {
    $operations = [
        'delete-bulk' => tr('Elimina selezionati'),
    ];
}

$operations['crea_fattura'] = [
        'text' => tr('Crea fattura'),
        'data' => [
            'title' => tr('Vuoi davvero creare una fattura per questi interventi?'),
            'msg' => '<br>{[ "type": "select", "label": "'.tr('Sezionale').'", "name": "id_segment", "required": 1, "values": "query=SELECT id, name AS descrizione FROM zz_segments WHERE id_module=\''.$id_fatture.'\' AND is_fiscale = 1 ORDER BY name", "value": "'.$id_segment.'" ]}',
            'button' => tr('Procedi'),
            'class' => 'btn btn-lg btn-warning',
            'blank' => false,
        ],
    ];

return $operations;
