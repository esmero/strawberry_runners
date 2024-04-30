<?php

namespace Drupal\strawberry_runners;
use JsonSerializable;
class VTTLine
{
  protected $starttime = NULL;
  protected $endstime = NULL;
  protected string $body;

  public function __construct(string $timesstart, string $timeend, string $body = '')
  {
    $start_time = explode('.',$timesstart);
    $end_time = explode('.',$timeend);
    // So annoying .. i end with negative numbers bc timezone things in the server.
    $base = strtotime('00:00:00', 0);

    $this->starttime = (strtotime($start_time[0], 0) - $base) +  floatval('.'.$start_time[1]);
    $this->endstime = (strtotime($end_time[0], 0)  - $base) + floatval('.'.$end_time[1]);
    $this->body = $body;
  }

  public function toHtml(): string
  {
    $seconds = $this->starttime;
    return "<a href=\"?time={$seconds}\" data-seconds=\"{$seconds}\">{$this->body}</a>";
  }
  public function appendToBody($transcript_line): string
  {
    $this->body =  $this->body . $transcript_line;
    return $this->body;
  }

  public function jsonSerialize(): array
  {
    return [
      "body" => $this->body,
      "html" => $this->toHtml(),
      "beginningTimestamp" => $this->starttime,
      "endingTimestamp" => $this->endstime,
    ];
  }
  public function getBody(): string {
    return  $this->body;
  }

  public function getStarttime(): ?float
  {
    return $this->starttime;
  }

  public function getEndstime(): ?float
  {
    return $this->endstime;
  }
}
