<?php

/**
 * WordPress Multisite Content Scraper
 * Fetches all posts and pages from WP REST API for multiple sites
 */

 /*

47 = https://legalaidlearning.justice.gov.uk
5 = https://ccrc.gov.uk
52 = https://pecs-contract-guide.service.justice.gov.uk
// messy
14 = https://ppo.gov.uk
54 = https://lawcom.gov.uk

*/

// ============================================
// CONFIGURATION - Edit these values
// ============================================
$BASE_URL = 'https://hale.docker';
$SITE_IDS = [5]; // Add your site IDs here
$OUTPUT_DIR = 'wordpress_content';
$ENV = 'PROD';
// ============================================

class WordPressMultisiteScraper
{
    private $baseUrl;
    private $outputDir;
    private $env;

    public function __construct($baseUrl, $outputDir = 'wordpress_content', $env = 'DEV')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
        $this->env = $env;
    }

    /**
     * Strip HTML tags and clean up text
     */
    private function stripHtml($html)
    {
        // Decode HTML entities
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove HTML tags
        $text = strip_tags($text);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Fetch all items from a paginated endpoint
     */
    private function fetchAllItems($endpoint, $siteId, $baseURL = '')
    {
        $items = [];
        $page = 1;
        $perPage = 100; //Max is 100 items per pad need to loop round other pages

        if(!empty($baseURL)){
            $apiURL = "{$baseURL}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
        }
        else {
            // Build URL for multisite
            if ($siteId === 1) {
                // Main site
                $apiURL = "{$this->baseUrl}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
            } else {
                // Subsite
                $apiURL = "{$this->baseUrl}/site-{$siteId}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
            }
        }

        $currentPage = "&page={$page}";
        
        echo "Fetching {$endpoint} page {$page} from site {$siteId}...\n";

        $apiResponse = $this->fetchFromApi($apiURL . $currentPage);

        $items = array_merge($items, $apiResponse['data']);
        echo "Fetched " . count($apiResponse['data']) . " {$endpoint}\n";
        
        // Check for total pages in headers
        preg_match('/X-WP-TotalPages: (\d+)/i', $apiResponse['headers'], $matches);

        $totalPages = isset($matches[1]) ? (int)$matches[1] : $page;

        echo "Endpoint Pages -  $totalPages pages found for {$endpoint} endpoint from site {$siteId}...\n";

        if($totalPages > 1){
            
            for ($page = 2; $page <= $totalPages; $page++) {
                echo "Fetching {$endpoint} endpoint page {$page} from site {$siteId}...\n";

                $currentPage = "&page={$page}";

                $apiResponse = $this->fetchFromApi($apiURL . $currentPage);
                $items = array_merge($items, $apiResponse['data']);
            }
        }


        return $items;
    }

    /**
     * Save raw content to text files
     */
    private function saveRawContent($item, $contentType, $siteId)
    {
        $typeDir = "{$this->outputDir}/site-{$siteId}/raw/{$contentType}";

        $rawContent = '';

        if (!is_dir($typeDir)) {
            mkdir($typeDir, 0755, true);
        }

        $rawContent .= isset($item['title']['rendered'])
                ? '<h1>' . $item['title']['rendered'] . '</h1>'
                : 'Untitled';


        $rawContent .= isset($item['content']['rendered'])
                ? $item['content']['rendered']
                : '';

        // Create filename
        $slug = isset($item['slug']) ? $item['slug'] : "{$contentType}-{$item['id']}";
        $slug = preg_replace('/[^a-z0-9-_]/', '', strtolower($slug));
        $filename = "{$slug}.txt";

        // Save to file
        $filePath = "{$typeDir}/{$filename}";
        file_put_contents($filePath, $rawContent);
    }
    /**
     * Save content to text files
     */
    private function saveContent($items, $contentType, $siteId, $siteTaxonomies)
    {

        $contentTypeTaxonomies = [];

        $typeDir = "{$this->outputDir}/site-{$siteId}/clean/{$contentType}";

        if (!is_dir($typeDir)) {
            mkdir($typeDir, 0755, true);
        }

        foreach ($siteTaxonomies as $taxonomy) {
            if(in_array($contentType, $taxonomy['types'])){
                $contentTypeTaxonomies[] = $taxonomy;
            }
        }

        foreach ($items as $item) {
            $this->saveRawContent($item, $contentType, $siteId);
            // Extract content
            $title = isset($item['title']['rendered'])
                ? $this->stripHtml($item['title']['rendered'])
                : 'Untitled';

            $content = isset($item['content']['rendered'])
                ? $this->stripHtml($item['content']['rendered'])
                : '';

            $excerpt = isset($item['excerpt']['rendered'])
                ? $this->stripHtml($item['excerpt']['rendered'])
                : '';

            // Create filename
            $slug = isset($item['slug']) ? $item['slug'] : "{$contentType}-{$item['id']}";
            $slug = preg_replace('/[^a-z0-9-_]/', '', strtolower($slug));
            $filename = "{$slug}.txt";

            // Combine content
            $fullText = "Site ID: {$siteId}\n";
            $fullText .= "Title: {$title}\n\n";

            //For each taxonomy that for the current post type
            foreach ($contentTypeTaxonomies as $taxonomy) {
                
                if(array_key_exists($taxonomy['slug'], $item) && !empty($item[$taxonomy['slug']])){
                   
                    $term_names = [];
                    $term_ids = $item[$taxonomy['slug']];

                    foreach ($term_ids as $term_id) {
                        if(array_key_exists($term_id, $taxonomy['terms'])){
                            $term_names[] = $taxonomy['terms'][$term_id]['name'];
                        }
                    }

                    if(!empty($term_names)){
                        $fullText .= $taxonomy['name'] . ": \n\n";
                        $fullText .= implode(", ", $term_names) . " \n\n";
                    }

                }
               
            }

            if (!empty($excerpt)) {
                $fullText .= "Excerpt: {$excerpt}\n\n";
            }

            if(array_key_exists('post_meta', $item) && !empty($item['post_meta'])){
                if(array_key_exists('summary', $item['post_meta']) && !empty($item['post_meta']['summary'])){
                    $fullText .= "Summary: {$item['post_meta']['summary']}\n\n";
                }
            }

            $fullText .= "Content:\n{$content}";



            // Save to file
            $filePath = "{$typeDir}/{$filename}";
            file_put_contents($filePath, $fullText);

            echo "Saved: {$filePath}\n";
        }
    }

    /**
     * Scrape a single site
     */
    private function scrapeSite($siteId, $baseURL = '')
    {
        
        $scrapeSummary = [];

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Processing Site ID: {$siteId}\n";
        echo str_repeat("=", 60) . "\n\n";

        $sitePostTypes = $this->getSitePostTypes($baseURL);

        // Question - getting for tax details - do we need?
        $siteTaxonomies = $this->getSiteTaxonomies($baseURL);

        foreach ($sitePostTypes as $postType) {
            $postTypeName =  $postType['name'];
            echo "=== Fetching {$postTypeName} from Site {$siteId} ===\n";

            $items = $this->fetchAllItems($postType['rest_base'], $siteId, $baseURL);

            echo "Total {$postTypeName} fetched: " . count($items) . "\n\n";

            if (!empty($items)) {
                echo "=== Saving {$postTypeName} from Site {$siteId} ===\n";
                //Question - do we use rest base or slug for folder - slug matches with taxonomies
                $this->saveContent($items, $postType['slug'], $siteId, $siteTaxonomies);
            }

            $scrapeSummary[] = [
                'postTypeName' => $postType['name'],
                'itemCount' => count($items)
            ];
        }

        return $scrapeSummary;

    }

    /**
     * Run the scraper for multiple sites
     */
    public function run($siteIds)
    {
        echo "Fetching content from: {$this->baseUrl}\n";
        echo "Output directory: {$this->outputDir}\n";
        echo "Target sites: " . implode(', ', $siteIds) . "\n";

        // Create output directory
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $summary = [];

        if($this->env == 'PROD'){
            $site_list = $this->getSiteList();

            foreach ($site_list as $site) {
              
                $siteId = (int) $site["blogID"];

               if(in_array($siteId, $siteIds)){

                    $summary[$siteId] = $this->scrapeSite($siteId, $site["url"]);
               }
               
            }
        }
        else {
            //Local
            foreach ($siteIds as $siteId) {
                $summary[$siteId] = $this->scrapeSite($siteId);
            }
        }

        // Print summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SCRAPING COMPLETE - SUMMARY\n";
        echo str_repeat("=", 60) . "\n";

        foreach ($summary as $siteId => $siteSummary) {

            $summary = "Site {$siteId}: ";
            $count = 0;
            foreach ($siteSummary as $postType) {
                if($count > 0){
                    $summary .= ", ";
                }
                $summary .= $postType['itemCount'] . " " . $postType['postTypeName'];
                $count++;
            }
            echo $summary . "\n";
            //$totalPosts += $counts['posts'];
            //$totalPages += $counts['pages'];
        }

       // echo "\nTotal: {$totalPosts} posts and {$totalPages} pages across " . count($siteIds) . " sites\n";
        echo "Saved to: {$this->outputDir}\n";
    }

    private function fetchFromApi($apiURL)
    {
        $apiResponse = ['data' => [], 'headers' => ''];

        /**
         * Definitive site list that have production domains.
         * This site list is pulled from our Prod site list API.
         *
         * The API contains a list of sites with their respective information.
         *
         * - 'blogID': The ID of the blog.
         * - 'domain': The domain of the site.
         * - 'slug': The unique given directory path of the site.
         */

        $ch = curl_init();

        // Set URL we want
        curl_setopt($ch, CURLOPT_URL, $apiURL);

        // Output as a string not to browser
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Set a timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Follow redirects (if ever useful)
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Make sure to verify SSL certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        // Optional but useful additions
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);     // Timeout for connection phase only
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);           // Limit number of redirects

        curl_setopt($ch, CURLOPT_HEADER, true); // Do we want this as an option

        // Execute response
        $response = curl_exec($ch);

        // Check for cURL errors
        // curl_errno() returns the error number (0 = no error)
        if (curl_errno($ch)) {
            echo "cURL Error Number: " . curl_errno($ch) . "\n";
            echo "cURL Error Message: " . curl_error($ch) . "\n";
            $data = null;
        } else {
            // Get HTTP response code
            // Server responded with 200 OK, 404 Not Found, etc.
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            // Useful debug info about the request
            #$info = curl_getinfo($ch);
            #echo "HTTP Code: " . $httpCode . "\n";
            #echo "Content Type: " . $info['content_type'] . "\n";
            #echo "Total Time: " . $info['total_time'] . " seconds\n";
            #echo "Download Size: " . $info['size_download'] . " bytes\n";

            if ($httpCode === 200) {
                // Process successful response

                // Parse headers and body
                $headers = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);

                $apiResponse['headers'] = $headers;

                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "JSON Decode Error: " . json_last_error_msg() . "\n";
                    $data = null;
                }
            } else {
                echo "HTTP Error: Received status code $httpCode\n";
                $data = null;
            }
        }

        // Always close the cURL handle to free up resources
        curl_close($ch);

        if ($data !== null) {
            $apiResponse['data'] = $data;
        }
        
        return $apiResponse;
    }

    private function getSiteTaxonomies($baseURL='')
    {

        $siteTaxonomies = [];

        $apiURL = $baseURL . '/wp-json/wp/v2/taxonomies';
       
        $apiResponse = $this->fetchFromApi($apiURL);
        $fetchedTaxonomies = $apiResponse['data'];


        // Quesiton - Nav menu breaks as it requires login - how do we determine this?
        $excludedTaxonomies = [
            'nav_menu', 
            'wp_pattern_category', 
        ];

        foreach ($fetchedTaxonomies as $taxonomy) {

            if(!in_array($taxonomy['slug'], $excludedTaxonomies)){
                $terms = [];

                $apiURL = $baseURL . '/wp-json/wp/v2/' . $taxonomy['rest_base'];
        
                $apiResponse = $this->fetchFromApi($apiURL);
                $fetchedTerms = $apiResponse['data'];

                foreach ($fetchedTerms as $term) {
                    $terms[$term['id']] = $term;
                }

                $taxonomy['terms'] = $terms;
                $siteTaxonomies[] = $taxonomy;
            }
        }
    
        return $siteTaxonomies;
    }

    private function getSitePostTypes($baseURL='')
    {

        $postTypes = [];

        $apiURL = $baseURL . '/wp-json/wp/v2/types';
       
        $apiResponse = $this->fetchFromApi($apiURL);
        $fetchedPostTypes = $apiResponse['data'];

        // Question - could we filter this in another way
        $excludedPostTypes = [
            'attachment', 
            'nav_menu_item', 
            'wp_block', 
            'wp_template', 
            'wp_template_part', 
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face'
        ];

        foreach ($fetchedPostTypes as $postType) {

            //Exclude core Post types
            if(!in_array($postType['slug'], $excludedPostTypes)){
                //Question - Decide if we want all fields or just pull out name, slug, rest_base
                // do we add check for rest_base
                $postTypes[] = $postType;
            }
        }

        return $postTypes;
    }


    private function getSiteList()
    {

        /**
         * Definitive site list that have production domains.
         * This site list is pulled from our Prod site list API.
         *
         * The API contains a list of sites with their respective information.
         *
         * - 'blogID': The ID of the blog.
         * - 'domain': The domain of the site.
         * - 'slug': The unique given directory path of the site.
         */

        $apiURL = 'https://websitebuilder.service.justice.gov.uk/wp-json/hc-rest/v1/sites/domain';
        $apiResponse = $this->fetchFromApi($apiURL);

        return $apiResponse['data'];
       
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Run scraper
$scraper = new WordPressMultisiteScraper($BASE_URL, $OUTPUT_DIR, $ENV);
$scraper->run($SITE_IDS);
