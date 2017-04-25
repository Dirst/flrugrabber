<?php

namespace Dirst\Flrugrabber;

/**
 * Get jobs from fl.ru in array.
 *
 * @author Dirst <dirst.guy@gmail.com>
 * @version 1.0
 */
class FlGrabber {
  const URL = 'https://www.fl.ru/projects/';
  const USERAGENT = 'User-Agent:Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.133 Safari/537.36';

  // Filter array.
  private $filter;

  //Special token after 1st request.
  private $fl_token;
  
  // Name of the cookie file to store FL.ru response cookies.
  private $cookies_file;
  
  /**
   * Create object.
   *
   * @params string $cookies_folder
   *   Folder on a server where created cookie will be stored
   */
  function __construct($cookies_folder = '') {
    // set unique cookies file name.
    $this->cookies_file = $coookes_folder . uniqid();

    // Get FL.ru token.
    $this->fl_token = $this->_getFlToken();
  }

  /**
   *  On object destruction
   */
  function __destruct() {
    // Remove cookies file
    if (file_exists($this->cookies_file)) {
      unlink($this->cookies_file);
    }
  }

  /**
   * Get jobs array.
   *
   * @param array $category_id
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
   * @param int $currency_id
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
  function getFilteredJobs($category_id = [], $common_categories = [], $kind = 1, $cost_from = 0, $currency_id = 2) {
    // Set up filter.
    $fields = [
      'action' => 'postfilter',
      'kind' => $kind,
      'pf_cost_from' => $cost_from,
      "currency_text_db_id" => $currency_id,
      'pf_currency' => $currency_id,
      'u_token_key' => $this->fl_token
    ];

    // Add special categories
    foreach ($category_id as $one) {
      $fields['pf_categofy'][1][$one] = 1;    
    }

    // Add common categories
    foreach ($common_categories as $one) {
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
    $jobs_html = $this->_curlRequest($filter, $headers);
    return $this->_parseJobs($jobs_html);
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
   * @param string $jobs_html
   *   Filtered jobs page from FL.ru.
   *
   * @return array
   *   Array of parsed jobs. 
   */
  private function _parseJobs($jobs_html) {
    // Create a DOM object
    $html = new simple_html_dom();

    // Load HTML from a string
    $html->load($jobs_html);
    $jobs = [];
    foreach ($html->find('#projects-list .b-post') as $one) {
      // Set title, id, link.
      $id = str_replace("project-item", '',  $one->id);
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
        $additional = new simple_html_dom();
        $additional = $additional->load($match[2]);
        $jobs[$id]['type'] = html_entity_decode(strip_tags($additional->find(".b-layout__txt_inline-block", 0)->innertext));

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
  private function _curlRequest($fields = NULL, $headers = []) {
    if ($curl = curl_init()) {
      curl_setopt($curl, CURLOPT_URL, self::URL);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($curl, CURLOPT_COOKIEFILE,  $this->cookies_file); 
      curl_setopt($curl, CURLOPT_COOKIEJAR,  $this->cookies_file); 

      // If not empty send POST.
      if ($fields) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
      }

      //Set Headers.
      curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
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
  private function _getFlToken() {
    $match = [];
    $curl_result = $this->_curlRequest(NULL, [self::USERAGENT]);
    
    // Find token key with regex.
    preg_match("/(var _TOKEN_KEY = \')(.+)(\';)/", $curl_result, $match);
    if (isset($match[2])) {
      return $match[2];
    } else {
      throw new Exception("Can't get TOKEN");
    }
  }

}