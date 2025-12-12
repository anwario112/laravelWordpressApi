<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class OrderService extends Controller
{
   
    private static $customerCache = [];
    private static $connectionCache = [];
    
    public function OrderDetails(Request $request)
    {
        $apiKey = $request->header('X-API-Key');
        if (!$apiKey) {
            return response()->json(['error' => 'API key is required'], 401);
        }
       
       
        if (!isset(self::$customerCache[$apiKey])) {
            self::$customerCache[$apiKey] = DB::table('Customers')
                      ->where('apikey', $apiKey)
                      ->first();
        }
        $customer = self::$customerCache[$apiKey];
       
        if (!$customer) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }
       
      
        if (!isset(self::$connectionCache[$customer->CustomerID])) {
            self::$connectionCache[$customer->CustomerID] = DB::table('ConnectionDetails')
                               ->where('customerID', $customer->CustomerID)
                               ->first();
        }
        $connectionDetails = self::$connectionCache[$customer->CustomerID];
       
        if (!$connectionDetails) {
            return response()->json(['error' => 'No database connection configured for this customer'], 500);
        }
       
        config([
            'database.connections.dynamic_connection' => [
                'driver' => 'mysql',
                'host' => $connectionDetails->ServerName,
                'database' => $connectionDetails->DatabaseName,
                'username' => $connectionDetails->Username,
                'password' => $connectionDetails->Password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]
        ]);
       
        DB::purge('dynamic_connection');
        $connectionName = 'dynamic_connection';
        
        try {
            
            $ordersData = DB::connection($connectionName)
                ->select("
                    SELECT 
                        -- Order information
                        o.id as order_id,
                        o.status,
                        o.total_amount,
                        o.currency,
                        o.date_created_gmt,
                        o.date_updated_gmt as date_completed_gmt,
                        
                        -- Billing information
                        b.first_name as billing_first_name,
                        b.last_name as billing_last_name,
                        b.company as billing_company,
                        b.address_1 as billing_address_1,
                        b.address_2 as billing_address_2,
                        b.city as billing_city,
                        b.state as billing_state,
                        b.postcode as billing_postcode,
                        b.country as billing_country,
                        b.email as billing_email,
                        b.phone as billing_phone,
                       
                        
                        -- Item information
                        l.product_id,
                        l.variation_id,
                        p.post_title as product_name,
                        l.product_qty as quantity,
                        l.product_gross_revenue as line_total,
                        l.product_net_revenue as line_subtotal,
                        l.coupon_amount as discount_amount,
                        
                       
                  
                        
                      
                        CASE 
                            WHEN l.product_qty > 0 THEN ROUND(l.product_gross_revenue / l.product_qty, 2)
                            ELSE 0 
                        END as unit_price
                        
                    FROM wp_wc_orders o
                    
                    -- Billing address 
                    INNER JOIN wp_wc_order_addresses b ON o.id = b.order_id 
                        AND b.address_type = 'billing'
                    
                            
                    -- Order items
                    LEFT JOIN wp_wc_order_product_lookup l ON o.id = l.order_id
                    LEFT JOIN wp_posts p ON l.product_id = p.ID
                
                    
                    WHERE o.status = 'wc-completed'
                    ORDER BY o.date_updated_gmt DESC, o.id, l.product_id
                ");

           
          

         
            $ordersWithItems = [];
            $currentOrderId = null;
            $currentOrder = null;
            $orderItems = [];
            
            foreach ($ordersData as $row) {
                
                if ($currentOrderId !== $row->order_id) {
                    
                    if ($currentOrder !== null) {
                        $ordersWithItems[] = $this->buildOrderArray($currentOrder, $orderItems);
                    }
                    
                  
                    $currentOrderId = $row->order_id;
                    $currentOrder = $row;
                    $orderItems = [];
                }
                
               
                if ($row->product_id) {
                    $item = [
                        'product_id' => $row->product_id,                   
                        'product_name' => $row->product_name,                         
                        'quantity' => $row->quantity,
                        'unit_price' => $row->unit_price,
                        'line_total' => $row->line_total,
                        'line_subtotal' => $row->line_subtotal,
                        'discount_amount' => $row->discount_amount
                    ];
                    $orderItems[] = $item;
                }
            }
            
            
            if ($currentOrder !== null) {
                $ordersWithItems[] = $this->buildOrderArray($currentOrder, $orderItems);
            }

          
            $totalRevenue = collect($ordersWithItems)->sum('total_amount');
            $currency = $ordersWithItems[0]['currency'] ?? 'USD';

            return response()->json([
                'success' => true,
                'data' => $ordersWithItems,
                'meta' => [
                    'total_orders' => count($ordersWithItems),
                    'total_revenue' => $totalRevenue,
                    'currency' => $currency
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching order details: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch order details',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function buildOrderArray($order, $items)
    {
        $itemsCollection = collect($items);
        
        return [
            'order_id' => $order->order_id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'date_created' => $order->date_created_gmt,
            'date_completed' => $order->date_completed_gmt,
            'customer' => [
                'name' => trim($order->billing_first_name . ' ' . $order->billing_last_name),
                'email' => $order->billing_email,
                'phone' => $order->billing_phone,
                'company' => $order->billing_company
            ],
            'billing_address' => [
                'first_name' => $order->billing_first_name,
                'last_name' => $order->billing_last_name,
                'company' => $order->billing_company,
                'address_1' => $order->billing_address_1,        
                'city' => $order->billing_city,           
                'country' => $order->billing_country,
                'full_address' => $this->formatAddress(
                    $order->billing_address_1,
                    $order->billing_address_2,
                    $order->billing_city,         
                    $order->billing_country
                )      
            ],
            'items' => $items,
            'order_summary' => [
                'subtotal' => $itemsCollection->sum('line_subtotal'),
                'total_discount' => $itemsCollection->sum('discount_amount'),
                'total_amount' => $order->total_amount,
                'total_items' => $itemsCollection->sum('quantity'),
                'unique_products' => $itemsCollection->count()
            ]
        ];
    }
   
    private function formatAddress($address1, $address2, $city, $country)
    {
        $addressParts = array_filter([
            $address1,
            $address2,
            $city,        
            $country
        ]);
        
        return implode(', ', $addressParts);
    }
}