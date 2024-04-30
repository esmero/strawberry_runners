<?php

namespace Drupal\strawberry_runners;

class VTTProcessor implements \Iterator
{
  protected $lines = [];
  protected $maxTime = NULL;
  protected $position = 0;

  public function __construct(string $transcriptOrPath)
  {
    if (preg_match('/WEBVTT/', $transcriptOrPath)) {
      $transcript = explode("\n", $transcriptOrPath);
    } else {
      $transcript = file($transcriptOrPath);
    }
    $this->position = 0;
    $this->lines = $this->normalizeLines($transcript);
  }

  protected function normalizeLines(array $lines): array
  {
    $newline = NULL;
    $bodyAppended = FALSE;
    $results = [];
    foreach ($lines as $index => $line) {
      if ($times = $this->validateTimeSpan($line)) {
        if ($newline && strlen(trim($newline->getBody()) > 0)) {
          // This would be a line added on a previous loop;
          $results[] = $newline;
          $this->maxTime = ($this->maxTime ?? 0) < $newline->getEndstime() ? $newline->getEndstime() : $this->maxTime;
        }
        $newline = new VTTLine($times[0], $times[1]);
        $bodyAppended = FALSE;
      }
      elseif ($newline) {
        if (is_string($line) && strlen(trim($line)) > 0 && !preg_match('/^\s*\d+$/', $line)) {
          $newline->appendToBody(" " . $line);
          $bodyAppended = TRUE;
        }
      }
    }
    if ($bodyAppended && $newline) {
      // becase we add to results on a Next valid timestamp, we will have missed the last line or if a single one.
      $results[] = $newline;
      //This will preserve the max extracted end time of the bunch at this class.
      $this->maxTime = ($this->maxTime ?? 0) < $newline->getEndstime() ? $newline->getEndstime() : $this->maxTime;
    }

    return array_values($results);
  }

    /**
     * @inheritDoc
     */
    public function current(): mixed
    {
      return $this->lines[$this->position];
    }

    /**
     * @inheritDoc
     */
    public function next(): void
    {
      $this->position++;
    }

    /**
     * @inheritDoc
     */
    public function key(): mixed
    {
      return $this->position;
    }

    /**
     * @inheritDoc
     */
    public function valid(): bool
    {
      return isset($this->lines[$this->position]);
    }

    /**
     * @inheritDoc
     */
    public function rewind(): void
    {
      $this->position = 0;
    }

  /**
   * @return null
   */
  public function getMaxTime()
  {
    return $this->maxTime;
  }

  protected function validateTimeSpan(string $time_as_vtt_string):array|false
    {
      $times = [];
      $parts = explode(' --> ', $time_as_vtt_string);
      /*
       *
       *   0-2	0:
          2-5	00:
          5-7	07
          7-11	.333
       */
      $re = '/^((?:[0-1][0-9]|[2][0-3]|[0-9]):){0,1}([0-5][0-9]:)(?:([0-5][0-9])(?:([.]\d{1,3}))?)?/m';
      if (count($parts)!== 2) {
        return FALSE;
      }
      foreach ($parts as $index => $part) {
        $matches = [];
        preg_match_all($re, $part, $matches, PREG_SET_ORDER, 0);

        if (!empty($matches[0])) {
          $matches = $matches[0];
          $matches = array_reverse($matches);
        }
        else {
          return FALSE;
        }
        if (isset($matches[0]) && str_starts_with($matches[0], '.') && strlen($matches[0]) == 4) {
          // Milliseconds
          $times[$index] = $matches[0];
        }
        else { return FALSE; }
        if (isset($matches[1])  && !empty($matches[1])) {
          //seconds
          $times[$index] = $matches[1] . $times[$index];
        }
        else {
          return FALSE;
        }
        if (isset($matches[2]) && !empty($matches[2])) {
          //minutes
          $times[$index] = $matches[2] . $times[$index];
        }
        else {
          return FALSE;
        }
        if (isset($matches[3]) && !empty($matches[3])) {
          //hours
          if (strlen($matches[3]) == 2) {
            $matches[3] = '0'. $matches[3];
          }
          $times[$index] = $matches[3] . $times[$index];
        }
        else {
          //That is OK, let's add 00:
          $times[$index] = '00:' . $times[$index];
        }
      }
      // This is obvious ... we return on every wrong number.
      if (count($times) == 2) {
        return $times;
      }
      else {
        return FALSE;
      }
    }
}
