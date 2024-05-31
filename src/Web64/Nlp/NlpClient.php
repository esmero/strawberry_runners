<?php

namespace Drupal\strawberry_runners\Web64\Nlp;

use Web64\Nlp\NlpClient as Web64NlpClient;

class NlpClient extends Web64NlpClient {

  const MAX_RETRY_HOST = 3;

  /**
   *  Get language code for text
   *
   * @param     $text
   * @param int $predictions
   *
   * @return mixed|null
   */
  public function fasttext($text, $predictions = 1) {
    //Predictions at least 1 are required if POSTING. GET is more forgiving
    //Also. We need to URL encode upfront here.
    $data = $this->post_call(
      '/fasttext', ['text' => rawurlencode($text), 'predictions' => $predictions]
    );
    if (isset($data['fasttext']) && isset($data['fasttext']['language'])) {
      return $data['fasttext'];
    }
    return NULL;
  }

  public function get_call($path, $params, $retry = 0) {
    $url = $this->api_url . $path;

    $retry++;

    if (!empty($params)) {
      $url .= '?' . http_build_query($params);
    }

    $result = @file_get_contents($url, FALSE);

    $response = isset($http_response_header) && isset($http_response_header[0])
      ? $this->isValidResponse($http_response_header[0]) : FALSE;
    if ($response && !empty($result)) {
      return json_decode($result, 1);
    }
    else {
      if ($retry >= static::MAX_RETRY_HOST) {
        return NULL;
      }
      $this->chooseHost();
      return $this->post_call($path, $params, $retry);
    }
  }

  public function post_call($path, $params, $retry = 0) {
    // Do not POST without query parameters.
    if(!is_array($params) || empty($params)) {
      return NULL;
    }
    $url = $this->api_url . $path;
    $retry++;
    if ($retry > static::MAX_RETRY_HOST) {
      return NULL;
    }
    $opts = [
      'http' =>
        [
          'method'  => 'POST',
          'header'  => 'Content-type: application/x-www-form-urlencoded',
          'content' => http_build_query($params),
        ],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($url, FALSE, $context);
    $response = isset($http_response_header) && isset($http_response_header[0])
      ? $this->isValidResponse($http_response_header[0]) : FALSE;
    if ($response && !empty($result)) {
      return json_decode($result, 1);
    }
    else {
      if ($retry >= static::MAX_RETRY_HOST) {
        return NULL;
      }
      $this->chooseHost();
      return $this->post_call($path, $params, $retry);
    }
  }

  // find working host
  protected function chooseHost() {
    $random_a = $this->api_hosts;
    shuffle($random_a); // pick random host
    foreach ($random_a as $api_url) {
      $content = @file_get_contents($api_url);
      if (!empty($content)) {
        $this->api_url = $api_url;
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Checks if a Python Webserver Response String is valid for us
   *
   * @param $response_string
   *   HTTP/1.0 THECODE SOME WORDS
   *
   * @return bool
   */
  protected function isValidResponse($response_string) {
    $response_code_split = explode(" ", $response_string);
    if (isset($response_code_split[1])) {
      $response_code = (int) $response_code_split[1];
      if ($response_code >= 200 && $response_code <= 299) {
        return TRUE;
      }
    }
    return FALSE;
  }
}