<?php

namespace Dirst\Flrugrabber;

/**
 * Get jobs from fl.ru in array.
 *
 * @author Dirst <dirst.guy@gmail.com>
 * @version 1.0
 */
class FlGrabber
{
    const URL = 'https://www.fl.ru/projects/';
    const USERAGENT = 'User-Agent:Mozilla/5.0 (Windows NT 6.1; WOW64) 
	  AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36';

    // Special token after 1st request.
    private $flToken;
  
    // Name of the cookie file to store FL.ru response cookies.
    private $cookiesFile;
  
   /**
    * Create object.
    *
    * @params string $cookiesFolder
    *   Folder on a server where created cookie will be stored
    */
    public function __construct($cookiesFolder = '')
    {
        // Set cookies folder to current script folder.
        if (!$cookiesFolder) {
            $cookiesFolder = dirname($_SERVER['SCRIPT_FILENAME']) . "/";
        }

        // set unique cookies file name.
        $this->cookiesFile = $cookiesFolder . uniqid();

        // Get FL.ru token.
        $this->flToken = $this->getFlToken();
    }

   /**
    *  On object destruction
    */
    public function __destruct()
    {
        // Remove cookies file
        if (file_exists($this->cookiesFile)) {
            unlink($this->cookiesFile);
        }
    }

   /**
    * Get jobs array.
    *
    * @param array $categoryId
    *   array of special IDs.
    * @param array $comon_categories
    *   array of common categories.
    * @param int $kind
    *   Type of jobs list.
    *   1 - projects.
    *   5 - all.
    *   4 - vacancy
    *   2 - konkurs.
    * @param int $cost_from
    *   Project cost (FROM).
    * @param int $currencyId
    *   ID of the currency
    *   2 - RUB
    *   1 - EUR
    *   0 - USD
    * @param string $u_token
    *   User token.
    *
    * @notice
    *   Only projects kind has been tested.
    *   It gets only 1st page of jobs.
    *
    * @todo make other pages available too.
    *
    * @return array
    *   Array of jobs.
    */
    public function getFilteredJobs(
        $categoryId = [],
        $commonCategories = [],
        $kind = 1,
        $costFrom = 0,
        $currencyId = 2
    ) {
        // Set up filter.
        $fields = [
        'action' => 'postfilter',
        'kind' => $kind,
        'pf_cost_from' => $costFrom,
        "currency_text_db_id" => $currencyId,
        'pf_currency' => $currencyId,
        'u_token_key' => $this->flToken
        ];

        // Add special categories
        foreach ($categoryId as $one) {
            $fields['pf_categofy'][1][$one] = 1;
        }

        // Add common categories
        foreach ($commonCategories as $one) {
            $fields['pf_categofy'][0][$one] = 0;
        }
    
        // Set up headers.
        $headers = [
        "Content-Type:application/x-www-form-urlencoded",
        "Accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
        'Accept-Language:ru,en-US;q=0.8,en;q=0.6,th;q=0.4',
        'Cache-Control:no-cache',
        'Host:www.fl.ru',
        'Pragma:no-cache',
        'Origin:https://www.fl.ru',
        'Referer:' .self::URL. '?kind=' . $kind,
        'Upgrade-Insecure-Requests:1',
        self::USERAGENT
        ];
    
        // Get jobs page in html.
        $jobsHtml = $this->curlRequest($fields, $headers);
        return $this->parseJobs($jobsHtml);
    }

   /**
    * Parse jobs to array from html.
    * job[id] = [
    *   id,
    *   title,
    *   link(no fl.ru domain in it),
    *   budget,
    *   description,
    *   type(project for example),
    *   time
    * ]
    *
    * @param string $jobsHtml
    *   Filtered jobs page from FL.ru.
    *
    * @return array
    *   Array of parsed jobs.
    */
    private function parseJobs($jobsHtml)
    {
        // Create a DOM object
        $html = new \simple_html_dom();

        // Load HTML from a string
        $html->load($jobsHtml);
        $jobs = [];
        foreach ($html->find('#projects-list .b-post') as $one) {
            // Set title, id, link.
            $id = str_replace("project-item", '', $one->id);
            $jobs[$id]['id'] = $id;
            $jobs[$id]['title'] = html_entity_decode(trim($one->find("h2 a", 0)->plaintext));
            $jobs[$id]['link'] = $one->find("h2 a", 0)->href;

            // Budget.
            $match = [];
            preg_match("/(document.write\(')(.+)('\))/", $one->find('script', 0)->innertext, $match);
            if (isset($match[2])) {
                $jobs[$id]['budget'] = html_entity_decode(strip_tags($match[2]));
            } else {
                throw new Exception("Can't get Budget");
            }

            // Description
            $match = [];
            preg_match("/(document.write\(')(.+)('\))/", $one->find('script', 1)->innertext, $match);
            if (isset($match[2])) {
                $jobs[$id]['description'] = html_entity_decode(strip_tags($match[2]));
            } else {
                throw new Exception("Can't get Description");
            }

            // Parse publish time.
            $match = [];
            preg_match("/(document.write\(')(.+)('\))/", $one->find('script', 2)->innertext, $match);
            if (isset($match[2])) {
                $additional = new \simple_html_dom();
                $additional = $additional->load($match[2]);
                $jobs[$id]['type'] = html_entity_decode(
                    strip_tags($additional->find(".b-layout__txt_inline-block", 0)->innertext)
                );

                $out = [];
                preg_match("/(b-layout__txt_inline-block\">)([^\<]+)(\<\/span\>)([^\<]+)/", $match[2], $out);

                if (isset($out[4])) {
                    $jobs[$id]['time'] = html_entity_decode(strip_tags($out[4]));
                } else {
                    throw new Exception("Can't parse publish time, no appropriate html element");
                }

                // Clear DOM to free memory on next job iteration.
                $additional->clear();
            } else {
                throw new Exception("Can't parse publish time, no script tag found");
            }
        }

        // Clear html DOM just in case to free memory.
        $html->clear();

        return $jobs;
    }

   /**
    * Send curl request.
    * GET request if filter array is empty and POST  otherwise.
    *
    * @param array $fields
    *   Array of the filter fields for POST request.
    * @param array $headers
    *   Headers that will be sent with request.
    *
    * @return string
    *   Page response.
    */
    private function curlRequest($fields = null, $headers = [])
    {
        if ($curl = curl_init()) {
            curl_setopt($curl, CURLOPT_URL, self::URL);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookiesFile);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $this->cookiesFile);

            // If not empty send POST.
            if ($fields) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
            }

            //Set Headers.
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            $out = curl_exec($curl);
            return $out;
        }
    }

   /**
    * Get fl.ru token that created after 1st request.
    *
    * @throws Exception
    *   Throwed when no token available.
    *
    * @return string
    *   FL.RU Token;
    */
    private function getFlToken()
    {
        $match = [];
        $curlResult = $this->curlRequest(null, [self::USERAGENT]);
    
        // Find token key with regex.
        preg_match("/(var _TOKEN_KEY = \')(.+)(\';)/", $curlResult, $match);
        if (isset($match[2])) {
            return $match[2];
        } else {
            throw new Exception("Can't get TOKEN");
        }
    }
}
