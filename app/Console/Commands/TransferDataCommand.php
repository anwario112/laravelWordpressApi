<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\SimpleProducts;
use Illuminate\Http\Request; 

class TransferDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transfer:data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transfer product data to WooCommerce on a schedule';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = new SimpleProducts();
        $request=new Request();

        $request->headers->set('X-API-Key','DASHGSQYBODCVRYVNCXVOTYWMVJJCUNHGXOA');
        $response=$controller->transferData($request);

        \Log::info('TransferDataCommand executed', ['response' => $response->getData()]);
        $this->info('data transfer completed:' . json_encode($response->getData()));

        return 0;
    }
}
