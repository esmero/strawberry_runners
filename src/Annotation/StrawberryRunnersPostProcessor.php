<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 10/7/18
 * Time: 2:26 PM
 */

namespace Drupal\strawberry_runners\Annotation;
use Drupal\Component\Annotation\Plugin;

/**
 * Defines a StrawberryRunnersPostProcessor item annotation object.
 *
 * Class StrawberryRunnersPostProcessor
 *
 * @package Drupal\strawberry_runners\Annotation
 *
 * @Annotation
 */
class StrawberryRunnersPostProcessor extends Plugin {

  const PRESAVE = 'preSave';
  const POSTSAVE = 'postSave';

  /**
   * The plugin id.
   *
   * @var string;
   */
  public $id;

  /**
   * @var string label;
   *
   * @ingroup plugin_translatable;
   */
  public $label;

  /**
   * The type of input this plugin can handle, either entity:entity_type or JSON
   *
   * @var string $input_type;
   *
   */

  public $input_type;

  /**
   * The Object property that contains the data needed by the Processor ::run method
   *
   * @var string $input_property;
   *
   */
  public $input_property;

  /**
   * The Object property that contains the additional data needed by the Processor ::run method
   *
   * @var string $input_arguments;
   *
   */
  public $input_arguments;

  /**
   * Processing stage: can be Entity PreSave or PostSave
   *
   * @var string $when;
   *
   */
  public $when = StrawberryRunnersPostProcessor::POSTSAVE;

}
