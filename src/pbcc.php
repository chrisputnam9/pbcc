<?php
/**
 * pbcc
 */

Class Pbcc extends Console_Abstract
{
    const VERSION = '0.0.1';

    // Name of script and directory to store config
    const SHORTNAME = 'pbcc';

    /**
     * Callable Methods
     */
    protected static $METHODS = [
        'alias',
        'browse',
        'search',
        'get',
        'post',
        'delete',
        'xpath',
    ];

    protected static $HIDDEN_CONFIG_OPTIONS = [
        'api_key',
        'api_url',
        'api_user_agent',
        'api_user_email',
        'api_cache_lifetime',
        'api_tokens_lifetime',
        'aliases',
    ];

    protected static $HTML_ENDPOINTS = [
        'search.xml',
        'templates.xml',
    ];

    // Config Variables
    protected $__api_key = ["Basecamp Classic API key", "string"];
    public $api_key = "";

    protected $__api_url = ["Basecamp Classic API URL", "string"];
    public $api_url = "";

    protected $__api_user_agent = ["User agent string to identify API requests. %s placeholder for user e-mail", "string"];
    public $api_user_agent = "PBCC (gh:chrisputnam9/pbcc) in use by %s";

    protected $__api_user_email = ["User e-mail to fill into user agent string", "string"];
    public $api_user_email = "";

    protected $__api_cache = ["Whether to cache results"];
    public $api_cache = true;

    protected $__api_cache_lifetime = ["How long to cache results in seconds (if enabled)"];
    public $api_cache_lifetime = 86400; // Default: 24 hours

    protected $__api_tokens_lifetime = ["How long to cache API browser tokens in seconds"];
    public $api_tokens_lifetime = 2592000; // Default: 30 days

    protected $__aliases = ["Aliases for endpoint URLs and segments"];
    protected $aliases = [];

    // Information for specific types
    protected $name_field = [
        'project' => 'name',
        'todo-item' => 'content',
    ];

    protected $link_template = [
        // 'account' => '', // No known URL
        // 'attachment' => '', // Use Download URL
        'calendar_entries' => '', // https://webpagefx.basecamphq.com/projects/1394938-wpfx-priorities-interactive/milestones
        'categories' => '', // ?
        'comments' => '',// https://webpagefx.basecamphq.com/projects/1394938-wpfx-priorities-interactive/todo_items/193471133/comments
        'data_reference' => '', // ?
        'company' => '/clients/%s', // tested
        // 'file' => '', // See attachment
        'messages' => '', // https://webpagefx.basecamphq.com/projects/1394938-wpfx-priorities-interactive/posts/96722167/comments
        'person' => '/people/%s/edit', // tested
        'project' => '/projects/%s', // tested
        'template' => '/templates/list/%s', // tested
        'time_tracking' => '',
        'todo-item' => '/todo_items/%s/comments', // tested
        'todo-list' => '/todo_lists/%s', // tested
    ];

    // Update this to your update URL, or remove it to disable updates
	public $update_version_url = "";

    protected $___alias = [
        "Look up or create an alias",
        ["Name of alias - will be converted to snake_case", "string", "required"],
        ["Value to set (if null, will look up current value instead)", "string"],
    ];
	public function alias($name, $value=null)
    {
    }

    protected $___browse = [
        "Open link to result in browser",
        ["Result ID", "int", "required"],
        ["Result type", "string", "required"],
    ];
    // for internal use, result may be object, then type is not required
    public function browse($result, $type=null)
    {
        $link = $this->getResultLink($result, $type);
        parent::openInBrowser($link);
    }

    protected $___search = [
        "Search GET data from the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Text to search for", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function search($endpoint, $query, $output=true)
    {
        return $this->xpath($endpoint, "/*/*/*[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '$query')]/..", $output);
    }

    protected $___xpath = [
        "Search GET data from Basecamp Classic API, using an XPath Expression - https://developer.mozilla.org/en-US/docs/Web/XPath",
        ["Endpoint slug", "string"],
        ["XPath Expression"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function xpath($endpoint, $xpath, $output=true)
    {
        $body = $this->get($endpoint, false);
        $xml = new SimpleXMLElement($body);

        $body = $xml->xpath($xpath);

        $this->outputAPIResults($body, $output);

        return $body;
    }

    protected $___get = [
        "GET data from the Basecamp Classic API",
        ["Endpoint slug or 'todo_templates'", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function get($endpoint, $output=true, $return_headers=false)
    {
        // Clean up endpoint
        $endpoint = trim($endpoint, " \t\n\r\0\x0B/");
        $endpoint = preg_replace("~\.xml$~", "", $endpoint);
        if (strpos($endpoint, '.xml') === false)
        {
            $endpoint = $endpoint . '.xml';
        }

        $html_endpoint = in_array($endpoint, self::$HTML_ENDPOINTS)
            ? str_replace('.xml', '.html', $endpoint)
            : false;
        $html_body = false;

        // Check for valid cached result if cache is enabled
        $body = "";
        if ($this->api_cache and !$return_headers)
        {
            $this->log("Cache is enabled - checking...");

            $body = $this->getCacheContents(['bc-api', $endpoint], $this->api_cache_lifetime);
        }

        if (empty($body))
        {

            // If HTML Endpoint, check for cached HTML
            if ($html_endpoint and $this->api_cache and !$return_headers)
            {
                $this->log("Absent XML cache data, checking for HTML cache data");
                $body = $this->getCacheContents(['bc-api', $html_endpoint], $this->api_cache_lifetime);
            }

            if (empty($body))
            {
                $this->log("Absent cache data, running fresh API request");

                // Get API curl object for endpoint
                $ch = $this->getAPICurl($endpoint);

                // Execute and check results
                list($body, $headers) = $this->runAPICurl($ch);

                if ($html_endpoint)
                {
                    // Cache HTML
                    $this->setCacheContents(['bc-api', $html_endpoint], $body);
                }

            }

            // If HTML endpoint, cache to html file and parse manually
            if ($html_endpoint)
            {
                switch($endpoint)
                {
                    case 'templates.xml':
                        // Create new XML object
                        $xml = new SimpleXMLElement('<templates type="array"></templates>');

                        if (preg_match_all('/\<a[^>]*href\s*\=\s*[\'"]\/templates\/list\/(\d+)[\'"][^>]*\>([^>]*)\</', $body, $matches))
                        {
                            foreach ($matches[1] as $i => $id)
                            {
                                $name = $matches[2][$i];

                                $xml_template = $xml->addChild('template');
                                $xml_template->addChild('status', 'template');

                                $xml_template->addChild('id', $id)
                                    ->addAttribute('type', 'integer');

                                $xml_template->addChild('name', $name);
                            }
                        }

                        $body = $xml->asXML();

                        break;

                    case 'search.xml':
                        // Not really in use yet, just for validation
                        break;

                    default:
                        $this->error("Parsing for '$endpoint' endpoint not yet implemented");
                        break;
                }
            }

            // Cache results
            $this->setCacheContents(['bc-api', $endpoint], $body);
        }

        if ($output)
        {
            if (empty($body))
            {
                $this->output('Empty response.');
            }
            else
            {
                $this->outputAPIResults($body);
            }
        }

        if ($return_headers)
        {
            return [$body, $headers];
        }

        return $body;
    }

    protected $___post = [
        "POST data to the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Body - main body to post - XML string expected in CLI", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function post($endpoint, $body="", $output=true, $return_headers=false)
    {
        // Get API curl object for endpoint
        $ch = $this->getAPICurl($endpoint);

        // Prepare data
        if (is_object($body) and $body instanceof SimpleXMLElement)
        {
            $body = "" . $body;
        }
        elseif (!is_string($body))
        {
            $this->error("Invalid body data type - must be string or SimpleXMLElement");
        }

        // Modify for posting data
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
        ]);

        // Execute and check results
        list($body, $headers) = $this->runAPICurl($ch);

        if ($output)
        {
            if (empty($body))
            {
                $this->output('Success!');
            }
            else
            {
                $this->output($body);
            }
        }

        if ($return_headers)
        {
            return [$body, $headers];
        }

        return $body;
    }

    protected $___delete = [
        "DELETE data from the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Fields to output in results - comma separated, false to output nothing, * to show all", "string"],
    ];
	public function delete($endpoint, $output=true)
    {
        // Get API curl object for endpoint
        $ch = $this->getAPICurl($endpoint);

        // Modify for deleting data
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ]);

        // Execute and check results
        list($body, $headers) = $this->runAPICurl($ch);

        if ($output)
        {
            if (empty($body))
            {
                $this->output('Success!');
            }
            else
            {
                $this->output($body);
            }
        }

        return $body;
    }

    /**
     * Prep Curl object to hit BC API
     * - endpoint may be api endpoint
     *   - or 'templates' for todo templates (custom)
     *   - or 'project_templates' (undocumented)
     */
    protected function getAPICurl($endpoint)
    {
        $this->setupAPI();
        $ch = $this->getCurl($this->api_url . '/' . $endpoint);

        // Typically a redirect means something was incorrect - eg. prompting for login
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_USERAGENT => sprintf($this->api_user_agent, $this->api_user_email),
        ]);

        if (in_array($endpoint, self::$HTML_ENDPOINTS))
        {
            $html_endpoint = str_replace('.xml', '', $endpoint);

            $tokens = $this->getAPIBrowserTokens();

            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_url . '/' . $html_endpoint,
                CURLOPT_HTTPHEADER => array(
                    'Cookie: twisted_token=' . $tokens['twisted_token'] .
                        '; session_token=' . $tokens['session_token']
                ),
                CURLOPT_TIMEOUT => 1800,
            ]);
            return $ch;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => array(
                'Accept: application/xml',
                'Content-Type: application/xml',
                'Authorization: Basic ' . base64_encode($this->api_key . ':X'),
            ),
        ]);
        return $ch;
    }

    /**
     * Get API Browser Tokens for custom "API" calls - eg. /templates
     * - Check cache for tokens
     * - Do a test curl to confirm validity
     * - Prompt for new tokens and save to cache if needed
     */
    protected $_apiBrowserTokens = null;
    protected function getAPIBrowserTokens()
    {
        // If cached on instance, we'll assume still valid
        if (is_null($this->_apiBrowserTokens))
        {
            $cached_tokens = false;
            $tokens_valid = false;

            // Check cache file
            $token_json = $this->getCacheContents('bc-api-browser-tokens.json', $this->api_tokens_lifetime);
            if (!empty($token_json))
            {
                $cached_tokens = json_decode($token_json, true);
                if (empty($cached_tokens))
                {
                    $this->warn("Likely syntax error with cache file - bc-api-browser-tokens.json", true);
                }
            }

            // Check validity of cached tokens (todo: have this checked during actual calls instead - but this is a fairly quick request)
            if ( ! empty($cached_tokens) )
            {

                // Prevent an infinite loop - our call to get below will get these tokens to use
                $this->_apiBrowserTokens = $cached_tokens;

                // try a request to the 'search' endpoint, check response headers
                $this->log("Checking if current cached browser tokens are valid...");
                $results = $this->get('search', false, true);
                $headers = $results[1];

                if (empty($headers['location']) or empty($headers['location'][0]) or(stripos($headers['location'][0], "login") === false))
                {
                    if (!empty($results[0]) and strlen($results[0]) > 5000)
                    {
                        $this->log("Tokens are valid!");
                        $tokens_valid = true;
                    }
                }
            }

            if ( ! $tokens_valid )
            {
                // Prompt for fresh tokens
                // Open browser for the user
                $search_page = $this->api_url . '/search';
                $this->output("The requested endpoint is implemented outside the BC API via custom methods.");
                $this->output("In order to support this, active standard session cookies are needed.");
                $this->output("Open $search_page in your browser (since it's a simple, fast page to load) - this should open now for you");

                $this->openInBrowser($search_page);

                $this->hr();
                $this->output("Follow these instructions to provide your session cookies to be used by this tool:");
                $this->br();
                $this->output("   1. Log in if not already logged in");
                $this->br();
                $this->output("   2. Open your developer tools (eg. F12, Ctrl+Shift+J or Cmd+J in Chrome)");
                $this->br();
                $this->output("   3. Navigate to the tab that shows cookies (eg. Application in Chrome)");
                $this->br();
                $twisted_token = $this->input("   4. Enter the value of the 'twisted_token' cookie");
                $this->br();
                $session_token = $this->input("   5. Enter the value of the 'session_token' cookie");
                $this->hr();

                $this->_apiBrowserTokens = [
                    'twisted_token' => $twisted_token,
                    'session_token' => $session_token,
                ];

                // Cache to file
                $this->setCacheContents('bc-api-browser-tokens.json', json_encode($this->_apiBrowserTokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            }

        }
        return $this->_apiBrowserTokens;
    }

    /**
     * Get results from pre-prepared curl object
     *  - Handle errors
     *  - Parse results
g    */
    protected function runAPICurl($ch, $close=true)
    {
        // Prep to receive headers
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2)
                {
                    return $len;
                }

                $headers[strtolower(trim($header[0]))][] = trim($header[1]);

                return $len;
            }
        );

        // Execute
        $body = $this->execCurl($ch);

        // Get response code
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Make sure valid response
        if ( empty($body) ) {
            $this->error("Request Error: " . curl_error($ch));
        }

        if ( $response_code == 404 )
        {
            $this->error("Response: $response_code - check endpoint URL");
        }

        if (
            $response_code < 200
            or $response_code > 399
        ) {
            $this->error("Response: $response_code", false);
            $this->error($body);
        }

        if ($response_code > 299)
        {
            $this->warn("Response: $response_code");
        }

        if ($close)
        {
            curl_close($ch);
        }

        return [$body, $headers];
    }

    /**
     * Output API Results with links
     */
    public function outputAPIResults ($body, $output=true)
    {
        if (is_string($output))
        {
            $output = trim(strtolower($output));
        }

        if ($output == false or $output === "false") return;

        if (is_string($output))
        {
            $output = explode(",", $output);
            $output = array_map('trim', $output);
        }
        else
        {
            $output = [];
        }

        if (is_string($body))
        {
            $body = new SimpleXMLElement($body);
        }

        foreach ($body as $result)
        {
            $type = $result->getName();
            $name_field = isset($this->name_field[$type]) ? $this->name_field[$type] : "name";

            $name = "";
            if (isset($result->$name_field)) $name = $result->$name_field;

            $name = $this->parseHtmlForTerminal($name);
            if (strlen($name) > 60)
            {
                $name = substr($name, 0, 57) . '...';
            }
            $name = str_pad($name, 60);

            $id_output = str_pad("(" . $result->id . ")", 15);

            $link = $this->getResultLink($result);

            $this->output("$id_output $name [$link]");

            foreach ($output as $output_field)
            {
                if ($output_field == '*')
                {
                    foreach ($result as $field => $value)
                    {
                        $this->output(" -- $field: $value");
                    }
                }
                else
                {
                    $value = isset($result->$output_field) ? $result->$output_field : "";
                    $this->output(" -- $output_field: $value");
                }
            }
        }

        $this->hr();
        $this->output("Total Results: " . count($body));
    }

    /**
     * Output SINGLE API Result with link
     * - just wraps body in array and calls outputAPIResults
     */
    public function outputAPIResult ($body, $output=true)
    {
        $this->outputAPIResults([$body], $output);
    }

    /**
     * Get link to result item
     */
    public function getResultLink($result, $type=null)
    {
        if (is_object($result))
        {
            $id = (int) $result->id;
            $type = (string) $result->getName();
        }
        else
        {
            $id = (int) $result;
        }

        if (empty($id) or empty($type))
        {
            $this->error('ID and Type required');
        }

        $link = "";

        $link_template = isset($this->link_template[$type]) ? $this->link_template[$type] : false;
        if ($link_template)
        {
            $link = $this->api_url . sprintf($link_template, $id);
        }
        elseif($type == 'comment')
        {
            //todo implement comment link logic based on type and type id
            return false;
        }
        else
        {
            $this->error("No link template defined for type: $type");
        }

        return $link;
    }

    /**
     * Set up BC API data
     * - prompt for any missing data and save to config
     */
    protected function setupAPI()
    {
        $api_url = $this->api_url;
        if (empty($api_url))
        {
            $api_url = $this->input("Enter Basecamp Classic base URL - eg. companyxyz.basecamphq.com", null, true);
        }
        $api_url = trim($api_url, " \t\n\r\0\x0B/");
        $api_url = preg_replace('~^(https?://)?([^/]+)(/.*)?$~', '$2', $api_url);
        $api_url = 'https://' . $api_url;
        $this->configure('api_url', $api_url, true);

        $api_key = $this->api_key;
        if (empty($api_key))
        {
            $api_key = $this->input("Enter Basecamp Classic API Key (from $api_url/people/me/edit)", null, true);
        }
        $api_key = trim($api_key);
        $this->configure('api_key', $api_key, true);

        $api_user_email = $this->api_user_email;
        if (empty($api_user_email))
        {
            $api_user_email = $this->input("Enter e-mail address to identify API usage", null, true);
        }
        $api_user_email = trim($api_user_email);
        $this->configure('api_user_email', $api_user_email, true);

        $this->saveConfig();
    }
}

// Check if PBCC is being used as a dependancy - do not run (child will run instead)
if (!defined('PBCC_DO_NOT_RUN') or !PBCC_DO_NOT_RUN)
{
    // Kick it all off
    Pbcc::run($argv);
}

// Note: leave this for packaging ?>
