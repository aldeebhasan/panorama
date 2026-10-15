<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;
use Generator;
use Illuminate\Support\Facades\Http;

/**
 * A paginated HTTP source whose field names differ from the ones the report
 * will use, so mapping is exercised too.
 */
class CrmCustomers extends Dataset
{
    public string $name = 'crm_customers';

    public function fetch(Params $params): Generator
    {
        for ($page = 1; ; $page++) {
            $rows = Http::baseUrl('https://crm.test')
                ->acceptJson()
                ->throw()
                ->get('/customers', ['updated_since' => $params->string('from'), 'page' => $page, 'per_page' => 2])
                ->json('data');

            if (!is_array($rows) || $rows === []) {
                return;
            }

            yield from $rows;

            // A short page is the last page.
            if (count($rows) < 2) {
                return;
            }
        }
    }

    public function schema(): Schema
    {
        return Schema::make()
            ->primaryKey('id')
            ->string('id', from: 'ext_id')
            ->string('name', from: 'custName')
            ->string('country')->nullable();
    }
}
