<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class StoreImages extends Controller
{
   
    public function viewImages(Request $request)
    {
        $apiKey=$request->header("X-API-Key");
        if(!$apiKey){
        return response()->json(['error' => 'API key is required'], 401);
     }

     $customer=DB::table('Customers')
     ->Where('ApiKey',$apiKey)
     ->first();

      if(!$customer){
                return response()->json(['error' => 'Invalid API key'], 401);
              }

        try {
            $directory = $request->get('directory', '/'); 
            $limit = $request->get('limit', 50);
            
            $files = Storage::disk('ftp')->listContents($directory, false);
            
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
            $images = [];
            
            foreach ($files as $file) {
                if ($file['type'] === 'file') {
                    $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                    
                    if (in_array($extension, $imageExtensions)) {
                        $images[] = [
                            'name' => $file['basename'] ?? basename($file['path']),
                            'path' => $file['path'],
                            'size' => $file['size'] ?? 0
                        ];
                    }
                }
                              
                if (count($images) >= $limit) {
                    break;
                }
            }
            
            return response()->json([
                'success' => true,
                'directory' => $directory,
                'total_images' => count($images),
                'images' => $images,
                'message' => count($images) > 0 ? 'Images found successfully' : 'No images found in this directory'
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to list images from FTP: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve images: ' . $e->getMessage()
            ], 500);
        }
    }

  
    public function AttachImageToProducts(Request $request)
    {

        try {
            set_time_limit(500);
         
            $apiKey = $request->header('X-API-Key');
            if (!$apiKey) {
                return response()->json(['error' => 'API key is required'], 401);
            }
            
            $customer = DB::table('Customers')
                          ->where('ApiKey', $apiKey)
                          ->first();
            
            if (!$customer) {
                return response()->json(['error' => 'Invalid API key'], 401);
            }
            
            $connectionDetails = DB::table('ConnectionDetails')
                                   ->where('CustomerID', $customer->CustomerID)
                                   ->first();
            
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

          
            $directory = $request->get('directory', '/');
            $dryRun = $request->get('dry_run', false);
            $limit = $request->get('limit', 50);
            $uploadPath = $request->get('upload_path', 'wp-content/uploads/');
            
           
            $files = Storage::disk('ftp')->listContents($directory, false);
            
            $imageExtensions = ['jpg', 'gif', 'jpeg', 'png', 'bmp', 'webp'];
            $results = [
                'processed' => 0,
                'skipped' => 0,
                'errors' => 0,
                'attachments' => [],
                'debug' => []
            ];

            if (!$dryRun) {
                DB::connection($connectionName)->beginTransaction();
            }

            foreach ($files as $file) {
                if ($results['processed'] >= $limit) break;

                if ($file['type'] === 'file') {
                    $filename = $file['basename'] ?? basename($file['path']);
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    if (in_array($extension, $imageExtensions)) {
                        $result = $this->processImage(
                            $filename, 
                            $file['path'], 
                            $connectionName, 
                            $uploadPath, 
                            $dryRun
                        );
                        
                        if ($result['status'] === 'processed') {
                            $results['processed']++;
                            $results['attachments'][] = $result['data'];
                        } elseif ($result['status'] === 'skipped') {
                            $results['skipped']++;
                            $results['debug'][] = [
                                'filename' => $filename,
                                'reason' => $result['message']
                            ];
                        } else {
                            $results['errors']++;
                            $results['debug'][] = [
                                'filename' => $filename,
                                'error' => $result['message']
                            ];
                            Log::error('Error processing image: ' . $filename . ' - ' . $result['message']);
                        }
                    }
                }
            }

            if (!$dryRun) {
                DB::connection($connectionName)->commit();
            } else {
                DB::connection($connectionName)->rollBack();
            }

            return response()->json([
                'success' => true,
                'results' => $results,
                'message' => sprintf(
                    'Processed %d images (%d skipped, %d errors)',
                    $results['processed'],
                    $results['skipped'],
                    $results['errors']
                )
            ]);
            
        } catch (Exception $e) {
            if (isset($connectionName)) {
                DB::connection($connectionName)->rollBack();
            }
            Log::error('Image attachment failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed: ' . $e->getMessage()
            ], 500);
        }
    }

private function processImage($filename, $filePath, $connectionName, $uploadPath, $dryRun = false)
{
    try {
        // Extract SKU from filename 
        $sku = $this->extractSkuFromFilename($filename);
        
        if (!$sku) {
            return [
                'status' => 'skipped',
                'message' => 'Could not extract SKU from filename: ' . $filename
            ];
        }

       
        $product = $this->findProductBySku($sku, $connectionName);

        if (!$product) {
            return [
                'status' => 'skipped',
                'message' => 'Product not found for SKU: ' . $sku
            ];
        }

        // Check if attachment already exists
        $existingAttachment = DB::connection($connectionName)
                               ->table('wp_posts')
                               ->where('post_name', strtolower(str_replace('.', '-', $filename)))
                               ->where('post_type', 'attachment')
                               ->first();

        if ($existingAttachment && !$dryRun) {
            return [
                'status' => 'skipped',
                'message' => 'Attachment already exists: ' . $filename
            ];
        }

        // Get image dimensions
        $imageInfo = $this->getImageInfo($filePath);
        
       
        $mimeType = $this->getMimeType($filename);
        
       
        $imageUrl = 'dddsoft-001-site3.dtempurl.com/wp-content/uploads/' . $filename;
        $attachmentId = null;

        if (!$dryRun) {
            $currentDateTime = now();
            
            // Insert attachment post with all required WordPress columns
            $attachmentId = DB::connection($connectionName)
                             ->table('wp_posts')
                             ->insertGetId([
                                 'post_author' => 1,
                                 'post_date' => $currentDateTime,
                                 'post_date_gmt' => $currentDateTime,
                                 'post_content' => '',
                                 'post_title' => pathinfo($filename, PATHINFO_FILENAME),
                                 'post_excerpt' => '',
                                 'post_status' => 'inherit',
                                 'comment_status' => 'open',
                                 'ping_status' => 'closed',
                                 'post_password' => '',
                                 'post_name' => strtolower(str_replace('.', '-', $filename)),
                                 'to_ping' => '',
                                 'pinged' => '',
                                 'post_modified' => $currentDateTime,
                                 'post_modified_gmt' => $currentDateTime,
                                 'post_content_filtered' => '',
                                 'post_parent' => $product->ID,
                                 'guid' => $imageUrl,
                                 'menu_order' => 0,
                                 'post_type' => 'attachment',
                                 'post_mime_type' => $mimeType,
                                 'comment_count' => 0
                             ]);

            // Add attachment metadata
            $this->addAttachmentMetadata($attachmentId, $filename, $imageInfo, $connectionName);
            
            // Set as product image based on filename pattern
            $this->setProductImage($attachmentId, $product->ID, $filename, $sku, $connectionName);
        }

        return [
            'status' => 'processed',
            'data' => [
                'filename' => $filename,
                'sku' => $sku,
                'product_id' => $product->ID,
                'product_name' => $product->post_title,
                'attachment_id' => $dryRun ? 'dry_run' : $attachmentId,
                'image_url' => $imageUrl,
                'dimensions' => $imageInfo
            ]
        ];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}
   
    private function findProductBySku($sku, $connectionName)
    {
        // Check by post_name (slug)
        $product = DB::connection($connectionName)
                    ->table('wp_posts')
                    ->where('post_name', strtolower(str_replace(' ', '-', $sku)))
                    ->where('post_type', 'product')
                    ->first();
        
        //  If not found ... try by SKU meta field
        if (!$product) {
            $product = DB::connection($connectionName)
                        ->table('wp_posts')
                        ->join('wp_postmeta', function($join) {
                            $join->on('wp_posts.ID', '=', 'wp_postmeta.post_id')
                                 ->where('wp_postmeta.meta_key', '_sku');
                        })
                        ->where('wp_postmeta.meta_value', $sku)
                        ->where('wp_posts.post_type', 'product')
                        ->select('wp_posts.*')
                        ->first();
        }
        
        // If still not found, try case-insensitive SKU search
        if (!$product) {
            $product = DB::connection($connectionName)
                        ->table('wp_posts')
                        ->join('wp_postmeta', function($join) {
                            $join->on('wp_posts.ID', '=', 'wp_postmeta.post_id')
                                 ->where('wp_postmeta.meta_key', '_sku');
                        })
                        ->whereRaw('LOWER(wp_postmeta.meta_value) = ?', [strtolower($sku)])
                        ->where('wp_posts.post_type', 'product')
                        ->select('wp_posts.*')
                        ->first();
        }
        
        return $product;
    }

   
    private function extractSkuFromFilename($filename)
    {
        // Remove extension
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Remove common suffixes (-0, -1, -2, etc)
        $sku = preg_replace('/[-_]\d+$/', '', $nameWithoutExt);
        
        return $sku ?: null;
    }

   //get image info dimension
    private function getImageInfo($filePath)
    {
        try {
          
            $imageContent = Storage::disk('ftp')->get($filePath);
            
            if ($imageContent) {
                // temporary file to get image dimensions
                $tempFile = tempnam(sys_get_temp_dir(), 'img_');
                file_put_contents($tempFile, $imageContent);
                
                $imageSize = getimagesize($tempFile);
                unlink($tempFile);
                
                if ($imageSize) {
                    return [
                        'width' => $imageSize[0],
                        'height' => $imageSize[1]
                    ];
                }
            }
        } catch (Exception $e) {
            Log::warning('Could not get image dimensions: ' . $e->getMessage());
        }
        
        return ['width' => 0, 'height' => 0];
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
        // Add attached file metadata
        DB::connection($connectionName)
          ->table('wp_postmeta')
          ->insert([
              'post_id' => $attachmentId,
              'meta_key' => '_wp_attached_file',
              'meta_value' => $filename
          ]);

        // Add attachment metadata
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

   
    private function setProductImage($attachmentId, $productId, $filename, $sku, $connectionName)
    {
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Check if this is a primary image 
        if ($nameWithoutExt === $sku || 
            preg_match('/[-_]0$/', $nameWithoutExt) ||
            !preg_match('/[-_]\d+$/', $nameWithoutExt)) {
            
         
            DB::connection($connectionName)
              ->table('wp_postmeta')
              ->updateOrInsert(
                  ['post_id' => $productId, 'meta_key' => '_thumbnail_id'],
                  ['meta_value' => $attachmentId]
              );
        } else {
            // Add to gallery
            $existingGallery = DB::connection($connectionName)
                                ->table('wp_postmeta')
                                ->where('post_id', $productId)
                                ->where('meta_key', '_product_image_gallery')
                                ->first();
            
            if ($existingGallery) {
                $galleryIds = explode(',', $existingGallery->meta_value);
                if (!in_array($attachmentId, $galleryIds)) {
                    $galleryIds[] = $attachmentId;
                    $newGalleryValue = implode(',', array_filter($galleryIds));
                    
                    DB::connection($connectionName)
                      ->table('wp_postmeta')
                      ->where('post_id', $productId)
                      ->where('meta_key', '_product_image_gallery')
                      ->update(['meta_value' => $newGalleryValue]);
                }
            } else {
                DB::connection($connectionName)
                  ->table('wp_postmeta')
                  ->insert([
                      'post_id' => $productId,
                      'meta_key' => '_product_image_gallery',
                      'meta_value' => $attachmentId
                  ]);
            }
        }
    }

  
    public function checkImagesMatchSkus(Request $request)
    {
        try {
          
            $apiKey = $request->header('X-API-Key');
            if (!$apiKey) {
                return response()->json(['error' => 'API key is required'], 401);
            }
            
            $customer = DB::table('Customers')
                          ->where('ApiKey', $apiKey)
                          ->first();
            
            if (!$customer) {
                return response()->json(['error' => 'Invalid API key'], 401);
            }
            
            $connectionDetails = DB::table('ConnectionDetails')
                                   ->where('CustomerID', $customer->CustomerID)
                                   ->first();
            
            if (!$connectionDetails) {
                return response()->json(['error' => 'No database connection configured for this customer'], 500);
            }
            
            // Setup dynamic database connection
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

          
            $directory = $request->get('directory', '/');
            $limit = $request->get('limit', 50);
            
          
            $files = Storage::disk('ftp')->listContents($directory, false);
            
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
            $matches = [];
            $processed = 0;
            $debug = [];

            foreach ($files as $file) {
                if ($processed >= $limit) break;

                if ($file['type'] === 'file') {
                    $filename = $file['basename'] ?? basename($file['path']);
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    if (in_array($extension, $imageExtensions)) {
                        $processed++;
                        
                       
                        $potentialSku = $this->extractSkuFromFilename($filename);
                        
                        if (!$potentialSku) {
                            $debug[] = [
                                'filename' => $filename,
                                'issue' => 'Could not extract SKU from filename'
                            ];
                            continue;
                        }
                        
                       
                        $product = $this->findProductBySku($potentialSku, $connectionName);
                        
                        if ($product) {
                            $matches[] = [
                                'filename' => $filename,
                                'extracted_sku' => $potentialSku,
                                'product_id' => $product->ID,
                                'product_name' => $product->post_title,
                                'product_slug' => $product->post_name
                            ];
                        } else {
                            $debug[] = [
                                'filename' => $filename,
                                'extracted_sku' => $potentialSku,
                                'issue' => 'No matching product found'
                            ];
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'total_checked' => $processed,
                'matches_found' => count($matches),
                'matches' => $matches,
                'debug_info' => $debug,
                'message' => sprintf(
                    'Checked %d images, found %d matching product SKUs',
                    $processed,
                    count($matches)
                )
            ]);
            
        } catch (Exception $e) {
            Log::error('SKU matching failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check SKUs: ' . $e->getMessage()
            ], 500);
        }
    }
}