<?php

namespace Aldeebhasan\Panorama\Tests\Datasets;

use Aldeebhasan\Panorama\Dataset\Dataset;
use Aldeebhasan\Panorama\Dataset\Params;
use Aldeebhasan\Panorama\Schema\Schema;

/**
 * A paginated HTTP source whose field names differ from the ones the report
 * will use, so mapping is exercised too.
 */
class CrmCustomers extends Dataset
{
    public string $name = 'crm_customers';
    public string $connection = 'crm_api';

    public function fetch(Params $params): iterable
    {
        return $this->http()->paginate(
            '/customers',
            ['updated_since' => $params->string('from')],
            perPage: 2,
        );
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
