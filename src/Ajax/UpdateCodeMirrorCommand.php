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
   * The Content that will be updated on the Code Mirror text area
   *
   * @var \stdClass;
   */
  protected $content;

  /**
   * The JQuery() selector
   *
   * @var string
   */
  protected $selector;

  /**
   * Constructs an AlertCommand object.
   *
   * @param string $text
   *   The text to be displayed in the alert box.
   */
  public function __construct($selector, $content) {
    $this->selector = $selector;
    $this->content = $content;

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

