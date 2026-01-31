<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; 
use Illuminate\Support\Facades\Http; 
use phpseclib3\Net\FTP;

class SimpleProducts extends Controller
{

   public function transferData(Request $request)
{
    try{
    $apiKey = $request->header('x-api-key') ?? 
              $request->header('X-API-KEY') ?? 
              $request->header('X-API-Key');
    if (!$apiKey) {
        return response()->json(['error' => 'API key is required'], 401);
    }
      Log::info('Attempting to connect to database...');
        
        // TEST: Check if we can connect to the default database
        try {
            $dbConnection = DB::connection()->getDatabaseName();
            Log::info('Default DB connected', ['database' => $dbConnection]);
        } catch (\Exception $e) {
            Log::error('Cannot connect to default database', [
                'error' => $e->getMessage(),
                'driver' => config('database.default')
            ]);
            return response()->json([
                'error' => 'Database connection failed',
                'message' => $e->getMessage()
            ], 500);
        }

        Log::info('Querying Customers table...');

    $customer = DB::table('Customers')->where('apikey', $apiKey)->first();
    if (!$customer) {
        return response()->json(['error' => 'Invalid API key'], 401);
    }
    
        Log::info('Customer found', ['customer_id' => $customer->CustomerID]);

    $connectionDetails = DB::table('ConnectionDetails')->where('customerID', $customer->CustomerID)->first();
    if (!$connectionDetails) {
        return response()->json(['error' => 'No database connection configured for this customer'], 500);
    }
    Log::info('Connection details found', [
            'host' => $connectionDetails->ServerName,
            'database' => $connectionDetails->DatabaseName
        ]);
    } catch (\Exception $e) {
        Log::error('Transfer data failed', [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'error' => 'An error occurred',
            'message' => $e->getMessage(),
            'line' => $e->getLine()
        ], 500);
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
             'options' => [
            \PDO::ATTR_TIMEOUT => 3600, 
            \PDO::ATTR_PERSISTENT => true,
        ],
        ]
    ]);
    DB::purge('dynamic_connection');
    $connectionName = 'dynamic_connection';
    $db = DB::connection($connectionName); 

    try {
        // set_time_limit(0);          
        // ini_set('max_execution_time', '0');
        DB::connection($connectionName)->beginTransaction();
           
       try{
        $this->processTaxonomies($connectionName);

        //  Fetch all existing products in one query
        $existingProducts = $db->table('wp_posts as p')
            ->join('wp_postmeta as pm', fn($join) => $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_sku'))
            ->where('p.post_type', 'product')
            ->where('p.post_status', 'publish')
            ->pluck('p.ID', 'pm.meta_value')
            ->toArray();

        // Fetch all products once
        $products = $db->table('custom_data')
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->whereNotNull('name')
            ->get();

        $ProductsProcessed=$products->count();
        
        $categoryTerms = $this->getTaxonomyTermMapping($connectionName, 'product_cat');
        $brandTerms = $this->getTaxonomyTermMapping($connectionName, 'brand');

        // Batch arrays for bulk operations
        $postsToInsert = [];
        $postsToUpdate = [];
        $postmetaToInsert = [];
        $termRelationshipsToInsert = [];
        $productsToCleanup = []; // Track products for taxonomy cleanup
        $updatedProductIds = []; // Track product IDs being updated (to delete their meta)
        $processedProductIds = []; // Track product IDs for WooCommerce hooks
        $now = now();
        $nowGmt = now();

        // Process products in batches
        $batchSize = 500;
        $productChunks = $products->chunk($batchSize);

        foreach ($productChunks as $chunk) {
            foreach ($chunk as $product) {
                $sku = $product->sku;
                $slug = strtolower(str_replace(' ', '-', $sku));

                if (isset($existingProducts[$sku])) {
                    $postId = $existingProducts[$sku];
                    $postsToUpdate[$postId] = [
                        'post_title' => $product->name,
                        'post_excerpt' => $product->description ?? '',
                        'post_modified' => $now,
                        'post_modified_gmt' => $nowGmt,
                    ];
                    
                    // Track product ID for hooks and meta updates
                    $processedProductIds[] = $postId;
                    $updatedProductIds[] = $postId;
                    
                    // Prepare meta and taxonomy for existing products
                    $this->prepareProductMeta($postId, $product, $postmetaToInsert);
                   $this->prepareTaxonomyRelationshipsWithCleanup($postId, $product, $categoryTerms, $brandTerms, $termRelationshipsToInsert, $productsToCleanup);
                } else {
                    $postsToInsert[] = [
                        'post_author' => 1,
                        'post_date' => $now,
                        'post_date_gmt' => $nowGmt,
                        'post_content' => '',
                        'post_title' => $product->name,
                        'post_excerpt' => $product->description ?? '',
                        'post_status' => 'publish',
                        'comment_status' => 'open',
                        'ping_status' => 'closed',
                        'post_name' => $slug,
                        'post_type' => 'product',
                        'post_mime_type' => '',
                        'to_ping' => '',
                        'pinged' => '',
                        'post_content_filtered' => '',
                        'post_modified' => $now,
                        'post_modified_gmt' => $nowGmt,
                        '_sku' => $sku, 
                        '_product' => $product, 
                    ];
                }
            }

            // Bulk insert new posts and prepare their meta/taxonomy
            if (!empty($postsToInsert)) {
                $newProductIds = $this->bulkInsertPosts($connectionName, $postsToInsert, $existingProducts, $postmetaToInsert, $termRelationshipsToInsert, $categoryTerms, $brandTerms);
                $processedProductIds = array_merge($processedProductIds, $newProductIds);
                $postsToInsert = []; 
            }

            // Bulk update existing posts
            if (!empty($postsToUpdate)) {
                $this->bulkUpdatePosts($connectionName, $postsToUpdate);
                $postsToUpdate = []; 
            }
        }

        // Delete existing meta for updated products first 
        if (!empty($updatedProductIds)) {
            $this->bulkDeleteProductMeta($connectionName, array_unique($updatedProductIds));
        }
        
        // Bulk insert all postmeta 
        if (!empty($postmetaToInsert)) {
            $this->bulkInsertPostmeta($connectionName, $postmetaToInsert);
        }
        
        $this->bulkCleanupChangedTaxonomies($productsToCleanup, $connectionName);
        
        if (!empty($termRelationshipsToInsert)) {
            $this->bulkInsertTermRelationships($connectionName, $termRelationshipsToInsert);
        }

        $this->updateTermCounts($connectionName);
        $this->processProductImages($connectionName);
        // $this->cleanupCustomData($connectionName);

        DB::connection($connectionName)->commit();
        $this->deleteDraftCheckoutOrders($connectionName);
        
        
        Log::info('WooCommerce products processed successfully with bulk operations.');
       return response()->json([
            'message' => 'The transfer is successful',
            'productsProcessed' => $ProductsProcessed
        ], 200);
    }catch (\Throwable $e) {                       
            \Log::error('transferData fatal error', [
                'msg'   => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;                                  
        }
    } catch (\Exception $e) {
        DB::connection($connectionName)->rollBack();
        return response()->json(['error' => 'An error occurred while transferring data.'], 500);
    }
}


private function processTaxonomies($connectionName)
{
    
    DB::connection($connectionName)->statement("
        INSERT INTO wp_terms (name, slug, term_group)
        SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug, 0
        FROM custom_data
        WHERE category IS NOT NULL AND category != ''
        ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug);
    ");
    
   
    DB::connection($connectionName)->statement("
        INSERT IGNORE INTO wp_term_taxonomy (term_id, taxonomy, description, parent)
        SELECT DISTINCT t.term_id, 'product_cat', '', 0
        FROM wp_terms t
        INNER JOIN (
            SELECT DISTINCT category 
            FROM custom_data 
            WHERE category IS NOT NULL AND category != ''
        ) cd ON BINARY t.name = BINARY cd.category;
    ");

    
    DB::connection($connectionName)->statement("
        INSERT INTO wp_terms (name, slug, term_group)
        SELECT DISTINCT subcategory AS name, LOWER(REPLACE(subcategory, ' ', '-')) AS slug, 0
        FROM custom_data
        WHERE subcategory IS NOT NULL AND subcategory != ''
        ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug);
    ");
    
   
    DB::connection($connectionName)->statement("
        INSERT IGNORE INTO wp_term_taxonomy (term_id, taxonomy, parent, description)
        SELECT 
            st.term_id, 
            'product_cat', 
            pt.term_id, 
            ''
        FROM custom_data cd
        INNER JOIN wp_terms st ON st.name = cd.subcategory COLLATE utf8mb4_unicode_ci
        INNER JOIN wp_terms pt ON pt.name = cd.category COLLATE utf8mb4_unicode_ci
        INNER JOIN wp_term_taxonomy pt_tax ON pt_tax.term_id = pt.term_id 
                                        AND pt_tax.taxonomy = 'product_cat'
        WHERE cd.subcategory IS NOT NULL 
        AND cd.subcategory != ''
        AND cd.category IS NOT NULL 
        AND cd.category != '';
    ");

   
 
     Log::info('Processing brands...');
    
  
    $brandsInData = DB::connection($connectionName)
        ->table('custom_data')
        ->whereNotNull('brand')
        ->where('brand', '!=', '')
        ->distinct()
        ->pluck('brand')
        ->toArray();
    
    


    $brandTermsInserted = DB::connection($connectionName)->statement("
        INSERT INTO wp_terms (name, slug, term_group)
        SELECT DISTINCT brand AS name, LOWER(REPLACE(brand, ' ', '-')) AS slug, 0
        FROM custom_data
        WHERE brand IS NOT NULL AND brand != ''
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            slug = VALUES(slug);
    ");

    Log::info("Brand terms inserted/updated: {$brandTermsInserted}");

    
    $brandTaxonomyInserted = DB::connection($connectionName)->statement("
        INSERT IGNORE INTO wp_term_taxonomy (term_id, taxonomy, description, parent)
        SELECT DISTINCT 
            t.term_id, 
            'brand', 
            '', 
            0
        FROM wp_terms t
        INNER JOIN custom_data cd ON BINARY t.name = BINARY cd.brand
        WHERE cd.brand IS NOT NULL 
        AND cd.brand != ''
        AND t.name IS NOT NULL
    ");
    

    Log::info("Brand taxonomy entries inserted: {$brandTaxonomyInserted}");

   
    $insertedBrands = DB::connection($connectionName)
        ->table('wp_terms as t')
        ->join('wp_term_taxonomy as tt', 't.term_id', '=', 'tt.term_id')
        ->where('tt.taxonomy', 'brand')
        ->select('t.name', 't.slug', 'tt.term_taxonomy_id')
        ->get();

    Log::info('Brands successfully inserted into WordPress:', ['brands' => $insertedBrands->toArray()]);
}


private function getTaxonomyTermMapping($connectionName, $taxonomy)
{
    $terms = DB::connection($connectionName)
        ->table('wp_terms as t')
        ->join('wp_term_taxonomy as tt', 't.term_id', '=', 'tt.term_id')
        ->where('tt.taxonomy', $taxonomy)
        ->select('t.name', 'tt.term_taxonomy_id')
        ->get();
    
    $mapping = [];
    foreach ($terms as $term) {
        $mapping[$term->name] = $term->term_taxonomy_id;
    }
    
    return $mapping;
}

private function bulkInsertPosts($connectionName, &$postsToInsert, &$existingProducts, &$postmetaToInsert, &$termRelationshipsToInsert, $categoryTerms, $brandTerms)
{
    $db = DB::connection($connectionName);
    $newProductIds = [];
    
   
    foreach (array_chunk($postsToInsert, 200) as $chunk) {
        $skus = [];
        $products = [];
        $cleanedChunk = [];
        
        // Extract SKUs, products and clean post data
        foreach ($chunk as $index => $post) {
            if (isset($post['_sku'])) {
                $skus[] = $post['_sku'];
                unset($post['_sku']);
            }
            if (isset($post['_product'])) {
                $products[] = $post['_product'];
                unset($post['_product']);
            }
            $cleanedChunk[] = $post;
        }
        
        // Insert posts one by one to get IDs 
        $postmetaSkus = [];
        
        foreach ($cleanedChunk as $index => $post) {
            $postId = $db->table('wp_posts')->insertGetId($post);
            $newProductIds[] = $postId;
            
            if (isset($skus[$index])) {
                $postmetaSkus[] = [
                    'post_id' => $postId,
                    'meta_key' => '_sku',
                    'meta_value' => $skus[$index],
                ];
                $existingProducts[$skus[$index]] = $postId;
                
                // Prepare meta and taxonomy for newly inserted products
                if (isset($products[$index])) {
                    $this->prepareProductMeta($postId, $products[$index], $postmetaToInsert);
                    $this->prepareTaxonomyRelationships($postId, $products[$index], $categoryTerms, $brandTerms, $termRelationshipsToInsert);
                }
            }
        }
        
       
        if (!empty($postmetaSkus)) {
            $db->table('wp_postmeta')->insert($postmetaSkus);
        }
    }
    
    return $newProductIds;
}

private function bulkUpdatePosts($connectionName, $postsToUpdate)
{
    $db = DB::connection($connectionName);
    
    if (empty($postsToUpdate)) return;
    
    $now = now();
    $nowGmt = now();
    
    
    foreach (array_chunk($postsToUpdate, 100, true) as $chunk) {
        $ids = array_keys($chunk);
        
       
        $titleCases = [];
        $excerptCases = [];
        
        foreach ($chunk as $id => $data) {
            $titleCases[] = "WHEN {$id} THEN " . $db->getPdo()->quote($data['post_title']);
            $excerptCases[] = "WHEN {$id} THEN " . $db->getPdo()->quote($data['post_excerpt'] ?? '');
        }
        
        $titleCase = 'CASE ID ' . implode(' ', $titleCases) . ' END';
        $excerptCase = 'CASE ID ' . implode(' ', $excerptCases) . ' END';
        
        $db->statement("
            UPDATE wp_posts 
            SET 
                post_title = {$titleCase},
                post_excerpt = {$excerptCase},
                post_modified = ?,
                post_modified_gmt = ?
            WHERE ID IN (" . implode(',', $ids) . ")
        ", [$now, $nowGmt]);
    }
}

private function prepareProductMeta($postId, $product, &$postmetaToInsert)
{
    $metaData = [
        '_price' => $product->price,
        '_regular_price' => $product->price,
        '_stock' => $product->stock,
        '_stock_status' => ($product->stock > 0) ? 'instock' : 'outofstock',
        '_visibility' => 'visible',
        '_manage_stock' => 'yes',
        '_backorders' => 'no',
        '_sold_individually' => 'no'
    ];
    


    foreach ($metaData as $key => $value) {
        if ($value !== null) {
            $postmetaToInsert[] = [
                'post_id' => $postId,
                'meta_key' => $key,
                'meta_value' => $value,
            ];
        }
    }
}

/**
 * Delete existing product meta for updated products
 * This prevents duplicates when inserting new meta values
 */
private function bulkDeleteProductMeta($connectionName, $productIds)
{
    $db = DB::connection($connectionName);
    
    if (empty($productIds)) {
        return;
    }
    
    // Meta keys that need to be updated (price, stock, etc.)
    $metaKeysToDelete = [
        '_price',
        '_regular_price',
        '_sale_price',
        '_stock',
        '_stock_status',
        '_visibility',
        '_manage_stock',
        '_backorders',
        '_sold_individually',
    ];
    
    // Delete existing meta for updated products in batches
    foreach (array_chunk($productIds, 500) as $chunk) {
        $db->table('wp_postmeta')
            ->whereIn('post_id', $chunk)
            ->whereIn('meta_key', $metaKeysToDelete)
            ->delete();
    }
    
    Log::info('✅ Deleted existing meta for ' . count($productIds) . ' updated products to prevent duplicates');
}

private function bulkInsertPostmeta($connectionName, $postmetaToInsert)
{
    $db = DB::connection($connectionName);
    
    // Simple INSERT - duplicates already prevented by deleting meta for updated products
    foreach (array_chunk($postmetaToInsert, 500) as $chunk) {
        $values = [];
        $params = [];
        
        foreach ($chunk as $meta) {
            $values[] = '(?, ?, ?)';
            $params[] = $meta['post_id'];
            $params[] = $meta['meta_key'];
            $params[] = $meta['meta_value'];
        }
        
        $sql = "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES " . 
               implode(', ', $values);
        
        $db->statement($sql, $params);
    }
}

private function prepareTaxonomyRelationships($postId, $product, $categoryTerms, $brandTerms, &$termRelationshipsToInsert)
{
    // Link to category
    if (!empty($product->category) && isset($categoryTerms[$product->category])) {
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $categoryTerms[$product->category],
        ];
    }

    // Link to subcategory
    if (!empty($product->subcategory) && isset($categoryTerms[$product->subcategory])) {
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $categoryTerms[$product->subcategory],
        ];
    }

    // Link to brand
    if (!empty($product->brand) && isset($brandTerms[$product->brand])) {
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $brandTerms[$product->brand],
        ];
    }
}

private function bulkInsertTermRelationships($connectionName, $termRelationshipsToInsert)
{
    $db = DB::connection($connectionName);
    
    // Remove duplicates 
    $unique = [];
    foreach ($termRelationshipsToInsert as $rel) {
        $key = $rel['object_id'] . '_' . $rel['term_taxonomy_id'];
        if (!isset($unique[$key])) {
            $unique[$key] = $rel;
        }
    }
    
    $termRelationshipsToInsert = array_values($unique);
    
    // Process in chunks
    foreach (array_chunk($termRelationshipsToInsert, 500) as $chunk) {
        $values = [];
        $params = [];
        
        foreach ($chunk as $rel) {
            $values[] = '(?, ?)';
            $params[] = $rel['object_id'];
            $params[] = $rel['term_taxonomy_id'];
        }
        
        $sql = "INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES " . 
               implode(', ', $values);
        
        $db->statement($sql, $params);
    }
}

private function updateProductMeta($connectionName, $postId, $product)
{
    $db = DB::connection($connectionName);
    
    $metaData = [
        '_price' => $product->price,
        '_regular_price' => $product->price,
        '_stock' => $product->stock,
        '_stock_status' => ($product->stock > 0) ? 'instock' : 'outofstock',
        '_visibility' => 'visible',
        '_manage_stock' => 'yes',
        '_backorders' => 'no',
        '_sold_individually' => 'no'
    ];

    foreach ($metaData as $key => $value) {
        if ($value !== null) {
            $db->statement("
                INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
            ", [$postId, $key, $value]);
        }
    }
}


private function linkProductToTaxonomies($connectionName, $postId, $sku)
{
   
    $productData = DB::connection($connectionName)
        ->table('custom_data')
        ->where('sku', $sku)
        ->first();

    if (!$productData) {
        return;
    }

    if (!empty($productData->category)) {
        DB::connection($connectionName)->statement("
            INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id)
            SELECT 
                ?, 
                tt.term_taxonomy_id
            FROM wp_terms t
            INNER JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
            WHERE t.name = ? 
            AND tt.taxonomy = 'product_cat'
        ", [$postId, $productData->category]);
    }

    // Link to subcategory
    if (!empty($productData->subcategory)) {
        DB::connection($connectionName)->statement("
            INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id)
            SELECT 
                ?, 
                tt.term_taxonomy_id
            FROM wp_terms t
            INNER JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
            WHERE t.name = ? 
            AND tt.taxonomy = 'product_cat'
        ", [$postId, $productData->subcategory]);
    }

    // LINK TO BRAND - FIXED WITH BINARY COMPARISON
    if (!empty($productData->brand)) {
        $brandTerm = DB::connection($connectionName)
            ->table('wp_terms as t')
            ->join('wp_term_taxonomy as tt', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'brand')
            ->whereRaw('BINARY t.name = ?', [$productData->brand]) 
            ->select('t.term_id', 'tt.term_taxonomy_id')
            ->first();
        
        if ($brandTerm) {
            DB::connection($connectionName)->statement("
                INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id)
                VALUES (?, ?)
            ", [$postId, $brandTerm->term_taxonomy_id]);
        }
    }
}


private function updateTermCounts($connectionName)
{
    // Update term counts for product categories
    DB::connection($connectionName)->statement("
        UPDATE wp_term_taxonomy AS tt
        SET tt.count = (
            SELECT COUNT(*)
            FROM wp_term_relationships AS tr
            INNER JOIN wp_posts AS p ON p.ID = tr.object_id
            WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
        )
        WHERE tt.taxonomy = 'product_cat';
    ");

    // Update term counts for brands
    DB::connection($connectionName)->statement("
        UPDATE wp_term_taxonomy AS tt
        SET tt.count = (
            SELECT COUNT(*)
            FROM wp_term_relationships AS tr
            INNER JOIN wp_posts AS p ON p.ID = tr.object_id
            WHERE tr.term_taxonomy_id = tt.term_taxonomy_id
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
        )
        WHERE tt.taxonomy = 'brand';
    ");
}






private function processProductImages($connectionName)
{
    $db = DB::connection($connectionName);
    $batchSize = 500;
    $offset = 0;
    $totalProcessed = 0;

    Log::info("🖼️ Image Processing Started");

    do {
        $batchStart = microtime(true);
        
        $products = $db->table('custom_data')
            ->select('sku', 'images')
            ->whereNotNull('images')
            ->where('images', '!=', '')
            ->whereNotIn('images', ['[]', 'null', '""'])
            ->offset($offset)
            ->limit($batchSize)
            ->get();

        if ($products->isEmpty()) {
            Log::info("📦 No more products to process at offset {$offset}");
            break;
        }

        Log::info("📦 Processing batch | Offset: {$offset} | Products in batch: " . count($products));

        $skus = $products->pluck('sku')->filter()->unique()->toArray();
        
        if (empty($skus)) {
            Log::warning("⚠️ No valid SKUs found in batch at offset {$offset}");
            $offset += $batchSize;
            continue;
        }

        Log::info("🔍 Found " . count($skus) . " unique SKUs");

        $wpProducts = $db->table('wp_posts as p')
            ->join('wp_postmeta as pm', fn($join) => $join->on('p.ID', '=', 'pm.post_id')->where('pm.meta_key', '_sku'))
            ->whereIn('pm.meta_value', $skus)
            ->where('p.post_type', 'product')
            ->where('p.post_status', 'publish')
            ->pluck('p.ID', 'pm.meta_value')
            ->toArray();

        Log::info("🎯 Matched " . count($wpProducts) . " WordPress products");

        $allImageBasenames = [];
        $totalImageUrls = 0;
        
        foreach ($products as $product) {
            $images = json_decode($product->images, true);
            if (is_array($images)) {
                $totalImageUrls += count($images);
                foreach ($images as $url) {
                    if (is_string($url)) {
                        $basename = pathinfo(basename($url), PATHINFO_FILENAME);
                        $allImageBasenames[] = strtolower(str_replace('.', '-', $basename));
                    }
                }
            }
        }

        Log::info("🖼️ Total image URLs to process: {$totalImageUrls}");
        Log::info("🔍 Unique image basenames: " . count(array_unique($allImageBasenames)));

        $existingAttachments = [];
        if (!empty($allImageBasenames)) {
            $existingAttachments = $db->table('wp_posts')
                ->whereIn('post_name', array_unique($allImageBasenames))
                ->where('post_type', 'attachment')
                ->pluck('ID', 'post_name')
                ->toArray();
            
            Log::info("✅ Found " . count($existingAttachments) . " existing attachments");
        }

        $this->bulkProcessImages($products, $wpProducts, $existingAttachments, $connectionName);

        $batchEnd = microtime(true);
        $batchTime = round($batchEnd - $batchStart, 2);
        $totalProcessed += count($products);
        
        Log::info("✅ Batch completed | Offset: {$offset} | Time: {$batchTime}s | Products: " . count($products));

        $offset += $batchSize;
    } while (true);

    Log::info("🖼️ Image Processing Completed | Total Products: {$totalProcessed}");
}

private function bulkProcessImages($products, $wpProducts, &$existingAttachments, $connectionName)
{
    $db = DB::connection($connectionName);
    $now = now();
    $cacheBuster = time();

    $allNewAttachments = [];
    $allMetadata = [];
    $allThumbnails = [];
    $allGalleries = [];
    
    $processedProducts = 0;
    $skippedProducts = 0;
    $totalImages = 0;
    $newImagesCreated = 0;
    $existingImagesReused = 0;

    Log::info("🔄 Starting bulk image processing for " . count($products) . " products");

    foreach ($products as $product) {
        $images = json_decode($product->images, true);
        if (!is_array($images)) {
            Log::warning("⚠️ Invalid images JSON for SKU: {$product->sku}");
            continue;
        }

        if (!isset($wpProducts[$product->sku])) {
            Log::warning("⚠️ WordPress product not found for SKU: {$product->sku}");
            $skippedProducts++;
            continue;
        }

        $wpProductId = $wpProducts[$product->sku];
        $thumbnailSet = false;
        $galleryIds = [];
        $productImageCount = count($images);
        $totalImages += $productImageCount;

        Log::info("📦 Processing product SKU: {$product->sku} | WordPress ID: {$wpProductId} | Images: {$productImageCount}");

        foreach ($images as $imageUrl) {
            if (!is_string($imageUrl)) {
                Log::warning("⚠️ Invalid image URL for SKU: {$product->sku}");
                continue;
            }

            $filename = basename($imageUrl);
            $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
            $fixedImageUrl = 'https://dddsoft-001-site3.dtempurl.com/wp-content/uploads/' . $filename . '?v=' . $cacheBuster;
            $postName = strtolower(str_replace('.', '-', $nameWithoutExt));

            $attachmentId = $existingAttachments[$postName] ?? null;

            if (!$attachmentId) {
                Log::info("➕ Creating NEW attachment: {$filename} for product {$product->sku}");
                $newImagesCreated++;
                
                $allNewAttachments[] = [
                    'post_author' => 1,
                    'post_date' => $now,
                    'post_date_gmt' => $now,
                    'post_content' => '',
                    'post_title' => $nameWithoutExt,
                    'post_excerpt' => '',
                    'post_status' => 'inherit',
                    'comment_status' => 'open',
                    'ping_status' => 'closed',
                    'post_name' => $postName,
                    'guid' => $fixedImageUrl,
                    'post_modified' => $now,
                    'post_modified_gmt' => $now,
                    'post_parent' => $wpProductId,
                    'post_type' => 'attachment',
                    'post_mime_type' => $this->getMimeType($filename),
                    'to_ping' => '',
                    'pinged' => '',
                    'post_content_filtered' => '',
                    '_temp_filename' => $filename,
                    '_temp_postname' => $postName,
                    '_temp_product_id' => $wpProductId,
                    '_temp_is_primary' => $this->isPrimaryImage($nameWithoutExt, $product->sku)
                ];
            } else {
                Log::info("♻️ Reusing EXISTING attachment ID {$attachmentId}: {$filename}");
                $existingImagesReused++;
            }

            if ($attachmentId) {
                if (!$thumbnailSet && $this->isPrimaryImage($nameWithoutExt, $product->sku)) {
                    $allThumbnails[$wpProductId] = $attachmentId;
                    $thumbnailSet = true;
                    Log::info("🖼️ Set as FEATURED image for product {$product->sku}");
                } else {
                    $galleryIds[] = $attachmentId;
                    Log::info("🖼️ Added to GALLERY for product {$product->sku}");
                }
            }
        }

        if (!empty($galleryIds)) {
            $allGalleries[$wpProductId] = $galleryIds;
        }
        
        $processedProducts++;
    }

    Log::info("📊 Bulk Processing Summary:");
    Log::info("   - Products processed: {$processedProducts}");
    Log::info("   - Products skipped: {$skippedProducts}");
    Log::info("   - Total images: {$totalImages}");
    Log::info("   - NEW attachments to create: {$newImagesCreated}");
    Log::info("   - EXISTING attachments reused: {$existingImagesReused}");
    Log::info("   - Featured images to set: " . count($allThumbnails));
    Log::info("   - Gallery updates: " . count($allGalleries));

    if (!empty($allNewAttachments)) {
        Log::info("💾 Starting bulk insert of {$newImagesCreated} new attachments...");
        $this->bulkInsertAttachments($allNewAttachments, $allMetadata, $allThumbnails, $allGalleries, $existingAttachments, $connectionName);
        Log::info("✅ Bulk insert completed");
    } else {
        Log::info("ℹ️ No new attachments to create - all images already exist");
    }

    if (!empty($allMetadata)) {
        Log::info("💾 Inserting " . count($allMetadata) . " metadata entries...");
        $this->bulkInsertMetadata($allMetadata, $connectionName);
        Log::info("✅ Metadata insert completed");
    }

    if (!empty($allThumbnails)) {
        Log::info("🖼️ Setting " . count($allThumbnails) . " featured images...");
        $this->bulkUpdateThumbnails($allThumbnails, $connectionName);
        Log::info("✅ Featured images set");
    }

    if (!empty($allGalleries)) {
        Log::info("🖼️ Updating " . count($allGalleries) . " product galleries...");
        $this->bulkUpdateGalleries($allGalleries, $connectionName);
        Log::info("✅ Galleries updated");
    }
}

private function bulkInsertAttachments(&$allNewAttachments, &$allMetadata, &$allThumbnails, &$allGalleries, &$existingAttachments, $connectionName)
{
    $db = DB::connection($connectionName);
    $totalInserted = 0;
    
    Log::info("📝 Inserting attachments in chunks of 200...");
    
    foreach (array_chunk($allNewAttachments, 200) as $chunkIndex => $chunk) {
        $chunkStart = microtime(true);
        $cleanedChunk = [];
        $tempData = [];
        
        foreach ($chunk as $attachment) {
            $tempData[] = [
                'filename' => $attachment['_temp_filename'],
                'postname' => $attachment['_temp_postname'],
                'product_id' => $attachment['_temp_product_id'],
                'is_primary' => $attachment['_temp_is_primary']
            ];
            
            unset($attachment['_temp_filename'], $attachment['_temp_postname'], 
                  $attachment['_temp_product_id'], $attachment['_temp_is_primary']);
            
            $cleanedChunk[] = $attachment;
        }
        
        foreach ($cleanedChunk as $index => $attachment) {
            $attachmentId = $db->table('wp_posts')->insertGetId($attachment);
            $temp = $tempData[$index];
            $totalInserted++;
            
            Log::info("   ✅ Created attachment ID {$attachmentId}: {$temp['filename']}");
            
            $allMetadata[] = [
                'post_id' => $attachmentId,
                'meta_key' => '_wp_attached_file',
                'meta_value' => $temp['filename']
            ];
            
            $metadata = serialize([
                'width' => 800,
                'height' => 800,
                'file' => $temp['filename'],
                'sizes' => [],
                'image_meta' => [
                    'aperture' => '0',
                    'credit' => '',
                    'camera' => '',
                    'caption' => '',
                    'created_timestamp' => time(),
                    'copyright' => '',
                    'focal_length' => '0',
                    'iso' => '0',
                    'shutter_speed' => '0',
                    'title' => '',
                    'orientation' => '0',
                    'keywords' => []
                ]
            ]);
            
            $allMetadata[] = [
                'post_id' => $attachmentId,
                'meta_key' => '_wp_attachment_metadata',
                'meta_value' => $metadata
            ];
            
            $existingAttachments[$temp['postname']] = $attachmentId;
            
            if ($temp['is_primary']) {
                $allThumbnails[$temp['product_id']] = $attachmentId;
                Log::info("   🌟 Marked as featured image for product {$temp['product_id']}");
            } else {
                if (!isset($allGalleries[$temp['product_id']])) {
                    $allGalleries[$temp['product_id']] = [];
                }
                $allGalleries[$temp['product_id']][] = $attachmentId;
            }
        }
        
        $chunkTime = round(microtime(true) - $chunkStart, 2);
        Log::info("   📦 Chunk " . ($chunkIndex + 1) . " completed: " . count($chunk) . " attachments in {$chunkTime}s");
    }
    
    Log::info("✅ Total attachments inserted: {$totalInserted}");
}

private function bulkInsertMetadata($allMetadata, $connectionName)
{
    $db = DB::connection($connectionName);
    $totalInserted = 0;
    
    foreach (array_chunk($allMetadata, 1000) as $chunkIndex => $chunk) {
        $db->table('wp_postmeta')->insert($chunk);
        $totalInserted += count($chunk);
        Log::info("   📦 Metadata chunk " . ($chunkIndex + 1) . ": " . count($chunk) . " entries inserted");
    }
    
    Log::info("✅ Total metadata entries inserted: {$totalInserted}");
}

private function bulkUpdateThumbnails($allThumbnails, $connectionName)
{
    $db = DB::connection($connectionName);
    
    $cases = [];
    $productIds = array_keys($allThumbnails);
    
    foreach ($allThumbnails as $productId => $attachmentId) {
        $cases[] = "WHEN {$productId} THEN {$attachmentId}";
        Log::info("   🖼️ Product {$productId} → Featured Image {$attachmentId}");
    }
    
    $caseStatement = 'CASE post_id ' . implode(' ', $cases) . ' END';
    
    // Use a safer bulk update approach
    foreach (array_chunk($allThumbnails, 100, true) as $chunk) {
        $ids = array_keys($chunk);
        $values = [];
        $params = [];
        
        foreach ($chunk as $productId => $attachmentId) {
            $values[] = '(?, ?, ?)';
            $params[] = $productId;
            $params[] = '_thumbnail_id';
            $params[] = $attachmentId;
        }
        
        $sql = "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES " . 
               implode(', ', $values) . 
               " ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
        
        $db->statement($sql, $params);
    }
    
    Log::info("✅ Featured images updated for " . count($allThumbnails) . " products");
}

private function bulkUpdateGalleries($allGalleries, $connectionName)
{
    $db = DB::connection($connectionName);
    
    $existingGalleries = $db->table('wp_postmeta')
        ->whereIn('post_id', array_keys($allGalleries))
        ->where('meta_key', '_product_image_gallery')
        ->pluck('meta_value', 'post_id')
        ->toArray();
    
    $updates = [];
    foreach ($allGalleries as $productId => $newIds) {
        $existingIds = isset($existingGalleries[$productId]) 
            ? array_filter(explode(',', $existingGalleries[$productId])) 
            : [];
        
        $allIds = array_unique(array_merge($existingIds, $newIds));
        $updates[] = [
            'post_id' => $productId,
            'meta_key' => '_product_image_gallery',
            'meta_value' => implode(',', $allIds)
        ];
        
        Log::info("   🖼️ Product {$productId} → Gallery: " . count($allIds) . " images total");
    }
    
    if (!empty($updates)) {
        foreach (array_chunk($updates, 500) as $chunk) {
            $values = [];
            $params = [];
            
            foreach ($chunk as $update) {
                $values[] = '(?, ?, ?)';
                $params[] = $update['post_id'];
                $params[] = $update['meta_key'];
                $params[] = $update['meta_value'];
            }
            
            $sql = "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES " . 
                   implode(', ', $values) . 
                   " ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
            
            $db->statement($sql, $params);
        }
    }
    
    Log::info("✅ Galleries updated for " . count($allGalleries) . " products");
}



private function processProductImagesBatch($images, $wpProductId, $product, &$existingAttachments, $connectionName)
{
    $db = DB::connection($connectionName);
    $now = now();
    $galleryIds = [];
    $thumbnailSet = false;
    $cacheBuster = time();

    // Batch arrays for bulk insert
    $postsToInsert = [];
    $postmetaToInsert = [];
    $postsToUpdate = [];
    $attachmentIdsToProcess = [];

    foreach ($images as $imageUrl) {
        if (!is_string($imageUrl)) continue;

        $filename = basename($imageUrl);
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $fixedImageUrl = 'https://dddsoft-001-site3.dtempurl.com/wp-content/uploads/' . $filename . '?v=' . $cacheBuster;
        $postName = strtolower(str_replace('.', '-', $nameWithoutExt));

        $attachmentId = $existingAttachments[$postName] ?? null;

        if (!$attachmentId) {
            // Prepare for bulk insert
            $postsToInsert[] = [
                'post_author' => 1,
                'post_date' => $now,
                'post_date_gmt' => $now,
                'post_content' => '',
                'post_title' => $nameWithoutExt,
                'post_excerpt' => '',
                'post_status' => 'inherit',
                'comment_status' => 'open',
                'ping_status' => 'closed',
                'post_name' => $postName,
                'guid' => $fixedImageUrl,
                'post_modified' => $now,
                'post_modified_gmt' => $now,
                'post_parent' => $wpProductId,
                'post_type' => 'attachment',
                'post_mime_type' => $this->getMimeType($filename),
                'to_ping' => '',
                'pinged' => '',
                'post_content_filtered' => '',
                '_filename' => $filename, 
                '_fixedUrl' => $fixedImageUrl, 
                '_postName' => $postName 
            ];
        } else {
            // Prepare for bulk update
            $postsToUpdate[$attachmentId] = [
                'guid' => $fixedImageUrl,
                'post_modified' => $now,
                'post_modified_gmt' => $now,
            ];
            
            $attachmentIdsToProcess[] = [
                'id' => $attachmentId,
                'filename' => $filename,
                'fixedUrl' => $fixedImageUrl,
                'postName' => $postName
            ];
        }

        // Handle thumbnail - check if this should be featured image
        if (!$thumbnailSet && $this->isPrimaryImage($nameWithoutExt, $product->sku)) {
            if ($attachmentId) {
                $postmetaToInsert[] = [
                    'post_id' => $wpProductId,
                    'meta_key' => '_thumbnail_id',
                    'meta_value' => $attachmentId
                ];
                $thumbnailSet = true;
                continue; // Don't add to gallery
            }
        }

        // Add to gallery (if we have an existing attachment ID)
        if ($attachmentId) {
            $galleryIds[] = $attachmentId;
        }
    }

    // === BULK INSERT NEW ATTACHMENTS ===
    if (!empty($postsToInsert)) {
        foreach ($postsToInsert as $postData) {
            $filename = $postData['_filename'];
            $fixedUrl = $postData['_fixedUrl'];
            $postName = $postData['_postName'];
            
            // Remove temporary keys
            unset($postData['_filename'], $postData['_fixedUrl'], $postData['_postName']);
            
            $attachmentId = $db->table('wp_posts')->insertGetId($postData);
            
            // Add metadata
            $postmetaToInsert[] = [
                'post_id' => $attachmentId,
                'meta_key' => '_wp_attached_file',
                'meta_value' => $filename
            ];
            
            $metadata = serialize([
                'width' => 800,
                'height' => 800,
                'file' => $filename,
                'sizes' => [],
                'image_meta' => [
                    'aperture' => '0',
                    'credit' => '',
                    'camera' => '',
                    'caption' => '',
                    'created_timestamp' => time(),
                    'copyright' => '',
                    'focal_length' => '0',
                    'iso' => '0',
                    'shutter_speed' => '0',
                    'title' => '',
                    'orientation' => '0',
                    'keywords' => []
                ]
            ]);
            
            $postmetaToInsert[] = [
                'post_id' => $attachmentId,
                'meta_key' => '_wp_attachment_metadata',
                'meta_value' => $metadata
            ];
            
            $existingAttachments[$postName] = $attachmentId;
            
            // Add to gallery if not thumbnail
            if (!$thumbnailSet && $this->isPrimaryImage(pathinfo($filename, PATHINFO_FILENAME), $product->sku)) {
                $postmetaToInsert[] = [
                    'post_id' => $wpProductId,
                    'meta_key' => '_thumbnail_id',
                    'meta_value' => $attachmentId
                ];
                $thumbnailSet = true;
            } else {
                $galleryIds[] = $attachmentId;
            }
        }
    }

    // BULK UPDATE EXISTING ATTACHMENTS
    if (!empty($postsToUpdate)) {
        foreach ($postsToUpdate as $attachmentId => $updateData) {
            $db->table('wp_posts')->where('ID', $attachmentId)->update($updateData);
        }

        // Delete old metadata in bulk
        $attachmentIds = array_keys($postsToUpdate);
        $db->table('wp_postmeta')
           ->whereIn('post_id', $attachmentIds)
           ->whereIn('meta_key', ['_wp_attached_file', '_wp_attachment_metadata'])
           ->delete();

        // Re-add metadata
        foreach ($attachmentIdsToProcess as $attachment) {
            $postmetaToInsert[] = [
                'post_id' => $attachment['id'],
                'meta_key' => '_wp_attached_file',
                'meta_value' => $attachment['filename']
            ];
            
            $metadata = serialize([
                'width' => 800,
                'height' => 800,
                'file' => $attachment['filename'],
                'sizes' => [],
                'image_meta' => [
                    'aperture' => '0',
                    'credit' => '',
                    'camera' => '',
                    'caption' => '',
                    'created_timestamp' => time(),
                    'copyright' => '',
                    'focal_length' => '0',
                    'iso' => '0',
                    'shutter_speed' => '0',
                    'title' => '',
                    'orientation' => '0',
                    'keywords' => []
                ]
            ]);
            
            $postmetaToInsert[] = [
                'post_id' => $attachment['id'],
                'meta_key' => '_wp_attachment_metadata',
                'meta_value' => $metadata
            ];
        }
    }

    //  BULK INSERT ALL POSTMETA 
    if (!empty($postmetaToInsert)) {
        // Chunk to avoid max packet size issues
        foreach (array_chunk($postmetaToInsert, 500) as $chunk) {
            $db->table('wp_postmeta')->insert($chunk);
        }
    }

    // Update gallery
    if (!empty($galleryIds)) {
        $this->updateProductGallery($wpProductId, $galleryIds, $connectionName);
    }
}




private function addFixedUrlAttachmentMetadata($attachmentId, $filename, $fixedImageUrl, $imageInfo, $connectionName)
{
    $db = DB::connection($connectionName);
    
   
    $db->table('wp_postmeta')->insert([
        'post_id' => $attachmentId,
        'meta_key' => '_wp_attached_file',
        'meta_value' => $filename
    ]);

  
    $metadata = serialize([
        'width' => $imageInfo['width'] ?? 800,
        'height' => $imageInfo['height'] ?? 800,
        'file' => $filename,
        'sizes' => [],
        'image_meta' => [
            'aperture' => '0',
            'credit' => '',
            'camera' => '',
            'caption' => '',
            'created_timestamp' => time(),
            'copyright' => '',
            'focal_length' => '0',
            'iso' => '0',
            'shutter_speed' => '0',
            'title' => '',
            'orientation' => '0',
            'keywords' => []
        ]
    ]);

    $db->table('wp_postmeta')->insert([
        'post_id' => $attachmentId,
        'meta_key' => '_wp_attachment_metadata',
        'meta_value' => $metadata
    ]);
}
private function addExternalAttachmentMetadata($attachmentId, $filename, $imageUrl, $imageInfo, $connectionName)
{
   
    DB::connection($connectionName)
      ->table('wp_postmeta')
      ->insert([
          'post_id' => $attachmentId,
          'meta_key' => '_wp_attached_file',
          'meta_value' => $imageUrl 
      ]);

   
    $metadata = serialize([
        'width' => $imageInfo['width'] ?? 0,
        'height' => $imageInfo['height'] ?? 0,
        'file' => $imageUrl, 
        'sizes' => [],
        'image_meta' => [
            'aperture' => '0',
            'credit' => '',
            'camera' => '',
            'caption' => '',
            'created_timestamp' => '0',
            'copyright' => '',
            'focal_length' => '0',
            'iso' => '0',
            'shutter_speed' => '0',
            'title' => '',
            'orientation' => '0',
            'keywords' => []
        ]
    ]);

    DB::connection($connectionName)
      ->table('wp_postmeta')
      ->insert([
          'post_id' => $attachmentId,
          'meta_key' => '_wp_attachment_metadata',
          'meta_value' => $metadata
      ]);
}


private function isPrimaryImage($basename, $sku)
{
    $cleanBasename = strtolower(trim($basename));
    $cleanSku = strtolower(trim($sku));
    return $cleanBasename === $cleanSku;
}



private function updateProductGallery($wpProductId, $newGalleryIds, $connectionName)
{
    $db = DB::connection($connectionName);
    
    $existingGallery = $db->table('wp_postmeta')
        ->where('post_id', $wpProductId)
        ->where('meta_key', '_product_image_gallery')
        ->first();

    $existingIds = $existingGallery ? array_filter(explode(',', $existingGallery->meta_value)) : [];
    $allIds = array_unique(array_merge($existingIds, $newGalleryIds));
    $newGalleryValue = implode(',', $allIds);

    $db->table('wp_postmeta')->updateOrInsert(
        ['post_id' => $wpProductId, 'meta_key' => '_product_image_gallery'],
        ['meta_value' => $newGalleryValue]
    );
}

private function getMimeType($filename)
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml'
    ];
    
    return $mimeTypes[$extension] ?? 'image/jpeg';
}



private function addAttachmentMetadata($attachmentId, $filename, $imageInfo, $connectionName)
{
   
    DB::connection($connectionName)
      ->table('wp_postmeta')
      ->insert([
          'post_id' => $attachmentId,
          'meta_key' => '_wp_attached_file',
          'meta_value' => $filename
      ]);

   
    $metadata = serialize([
        'width' => $imageInfo['width'],
        'height' => $imageInfo['height'],
        'file' => $filename,
        'sizes' => [],
        'image_meta' => [
            'aperture' => '0',
            'credit' => '',
            'camera' => '',
            'caption' => '',
            'created_timestamp' => '0',
            'copyright' => '',
            'focal_length' => '0',
            'iso' => '0',
            'shutter_speed' => '0',
            'title' => '',
            'orientation' => '0',
            'keywords' => []
        ]
    ]);

    DB::connection($connectionName)
      ->table('wp_postmeta')
      ->insert([
          'post_id' => $attachmentId,
          'meta_key' => '_wp_attachment_metadata',
          'meta_value' => $metadata
      ]);
}


private function cleanupCustomData($connectionName)
{
    try {
        Log::info('Starting cleanup of custom_data table');
        
        $countBefore = DB::connection($connectionName)
            ->table('custom_data')
            ->count();
        
        Log::info("Records in custom_data before cleanup: {$countBefore}");
        
        DB::connection($connectionName)
            ->table('custom_data')
            ->delete();
        
        $countAfter = DB::connection($connectionName)
            ->table('custom_data')
            ->count();
        
        Log::info("Records in custom_data after cleanup: {$countAfter}");
        Log::info('Custom_data table cleanup completed successfully');
        
    } catch (\Exception $e) {
        Log::error('Error during custom_data cleanup: ' . $e->getMessage());
        throw $e;
    }
}


private function deleteDraftCheckoutOrders($connectionName)
{
    $db = DB::connection($connectionName);
    
    try {
        Log::info('🗑️ Starting deletion of draft checkout orders...');
        
        $deleted = $db->table('wp_wc_orders')
            ->where('status', 'wc-checkout-draft')
            ->where('customer_id',0)
            ->delete();
        
        Log::info("✅ Deleted {$deleted} draft checkout orders");
        
        return $deleted;
        
    } catch (\Exception $e) {
        Log::error('❌ Error deleting draft checkout orders: ' . $e->getMessage());
        throw $e;
    }
}

private function prepareTaxonomyRelationshipsWithCleanup($postId, $product, $categoryTerms, $brandTerms, &$termRelationshipsToInsert, &$productsToCleanup)
{
    // Build new taxonomy IDs for this product
    $newTaxonomyIds = [];
    
    // Category
    if (!empty($product->category) && isset($categoryTerms[$product->category])) {
        $newTaxonomyIds[] = $categoryTerms[$product->category];
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $categoryTerms[$product->category],
        ];
    }
    
    // Subcategory
    if (!empty($product->subcategory) && isset($categoryTerms[$product->subcategory])) {
        $newTaxonomyIds[] = $categoryTerms[$product->subcategory];
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $categoryTerms[$product->subcategory],
        ];
    }
    
    // Brand
    if (!empty($product->brand) && isset($brandTerms[$product->brand])) {
        $newTaxonomyIds[] = $brandTerms[$product->brand];
        $termRelationshipsToInsert[] = [
            'object_id' => $postId,
            'term_taxonomy_id' => $brandTerms[$product->brand],
        ];
    }
    
    // Store for later bulk comparison
    $productsToCleanup[$postId] = $newTaxonomyIds;
}

private function bulkCleanupChangedTaxonomies($productsToCleanup, $connectionName)
{
    if (empty($productsToCleanup)) return;
    
    $db = DB::connection($connectionName);
    $productIds = array_keys($productsToCleanup);
    
    // Fetch ALL current relationships for these products in ONE query
    $currentRelationships = $db->table('wp_term_relationships as tr')
        ->join('wp_term_taxonomy as tt', 'tr.term_taxonomy_id', '=', 'tt.term_taxonomy_id')
        ->whereIn('tr.object_id', $productIds)
        ->whereIn('tt.taxonomy', ['product_cat', 'brand'])
        ->select('tr.object_id', 'tr.term_taxonomy_id')
        ->get()
        ->groupBy('object_id');
    
    // Compare and find what needs deletion
    $relationshipsToDelete = [];
    
    foreach ($productsToCleanup as $postId => $newTaxonomyIds) {
        $currentTaxonomyIds = isset($currentRelationships[$postId]) 
            ? $currentRelationships[$postId]->pluck('term_taxonomy_id')->toArray() 
            : [];
        
        // Find old taxonomy IDs that are NOT in the new list
        $toDelete = array_diff($currentTaxonomyIds, $newTaxonomyIds);
        
        if (!empty($toDelete)) {
            foreach ($toDelete as $taxonomyId) {
                $relationshipsToDelete[] = [
                    'object_id' => $postId,
                    'term_taxonomy_id' => $taxonomyId
                ];
            }
        }
    }
    
    // Bulk delete old relationships
    if (!empty($relationshipsToDelete)) {
        foreach (array_chunk($relationshipsToDelete, 500) as $chunk) {
            $conditions = [];
            foreach ($chunk as $rel) {
                $conditions[] = "(object_id = {$rel['object_id']} AND term_taxonomy_id = {$rel['term_taxonomy_id']})";
            }
            
            $db->statement("
                DELETE FROM wp_term_relationships 
                WHERE " . implode(' OR ', $conditions)
            );
        }
        
        Log::info("🗑️ Bulk deleted " . count($relationshipsToDelete) . " changed taxonomy relationships");
    }
}


}
