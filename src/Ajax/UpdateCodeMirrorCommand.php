<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/1/19
 * Time: 11:30 AM
 */

namespace Drupal\strawberry_runners\Ajax;
use Drupal\Core\Ajax\CommandInterface;
class UpdateCodeMirrorCommand implements CommandInterface
{

  /**
   * Constructs an AlertCommand object.
   *
   * @param string $text
   *   The text to be displayed in the alert box.
   * @param string $selector
   */
  public function __construct(
      /**
       * The JQuery() selector
       */
      protected $selector,
      /**
       * The Content that will be updated on the Code Mirror text area
       *
       * @var \stdClass;
       */
      protected $content
  )
  {
  }

  /**
   * Implements Drupal\Core\Ajax\CommandInterface:render().
   */
  public function render() {

    return [
      'command' => 'strawberry_runners_codemirror',
      'selector' => $this->selector,
      'content' => $this->content,
    ];
  }
}

