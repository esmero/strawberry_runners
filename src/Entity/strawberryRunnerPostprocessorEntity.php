<?php

namespace Drupal\strawberry_runners\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;


/**
 * Defines the Strawberry Key Name Providers entity.
 *
 * @ConfigEntityType(
 *   id = "strawberry_runners_postprocessor",
 *   label = @Translation("Strawberry Runners Post Processor"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\strawberry_runners\Entity\Controller\strawberryRunnerPostProcessorEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\strawberry_runners\Form\strawberryRunnerPostprocessorEntityForm",
 *       "edit" = "Drupal\strawberry_runners\Form\strawberryRunnerPostprocessorEntityForm",
 *       "delete" = "Drupal\strawberry_runners\Form\strawberryRunnerPostprocessorEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\strawberry_runners\strawberryRunnerPostProcessorEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "strawberry_runners_postprocessor",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "weight" = "weight",
 *     "active" = "active"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "weight",
 *     "pluginconfig",
 *     "pluginid",
 *     "active",
 *     "parent",
 *     "depth"
 *   },
 *   links = {
 *     "canonical" = "/admin/config/archipelago/strawberry_runner_postprocessor/{strawberry_runners_postprocessor}",
 *     "add-form" = "/admin/config/archipelago/strawberry_runner_postprocessor/add",
 *     "edit-form" = "/admin/config/archipelago/strawberry_runner_postprocessor/{strawberry_runners_postprocessor}/edit",
 *     "delete-form" = "/admin/config/archipelago/strawberry_runner_postprocessor/{strawberry_runners_postprocessor}/delete",
 *     "collection" = "/admin/config/archipelago/strawberry_runner_postprocessor"
 *   }
 * )
 */
class strawberryRunnerPostprocessorEntity extends ConfigEntityBase implements strawberryRunnerPostprocessorEntityInterface {

  /**
   * The Strawberry Runners Post Processor Entity ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The Strawberry Runners Post Processor Entity label.
   *
   * @var string
   */
  protected $label = '';

  /**
   * The weight of the post processor.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * The parent of the post processor.
   *
   * @var string
   */
  protected $parent = '';

  /**
   * The depth of the post processor.
   *
   * @var int
   */
  protected $depth = 0;

  /**
   * The plugin id that will be initialized with this config.
   *
   * @var string
   */
  protected $pluginid;


  /**
   * If the plugin should be processed or not.
   *
   * @var boolean
   */
  protected $active = true;

  /**
   * Plugin specific Config
   *
   * @var array
   */
  protected $pluginconfig = [];

  /**
   * @return string
   */
  public function getPluginid(): string {
    return $this->pluginid ?: '';
  }

  /**
   * @param string $pluginid
   */
  public function setPluginid(string $pluginid): void {
    $this->pluginid = $pluginid;
  }

  /**
   * @return bool
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * @param bool $active
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * @return string
   */
  public function getParent(): string {
    return $this->parent;
  }

  /**
   * @param string $parent
   */
  public function setParent($parent): void {
    $this->parent = $parent;
  }

  /**
   * @return int
   */
  public function getDepth(): int {
    return $this->depth;
  }

  /**
   * @param int $depth
   */
  public function setDepth(int $depth): void {
    $this->depth = $depth;
  }
  /**
   * @return array
   */
  public function getPluginconfig(): array {
    return $this->pluginconfig ?:[];
  }

  /**
   * @param array $pluginconfig
   */
  public function setPluginconfig(array $pluginconfig): void {
    $this->pluginconfig = $pluginconfig;
  }


  /**
   * Sorts the Post processor entities, putting disabled ones at the bottom.
   *
   * @see \Drupal\Core\Config\Entity\ConfigEntityBase::sort()
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {

    // Check if the entities are flags, if not go with the default.
    if ($a instanceof strawberryRunnerPostprocessorEntityInterface && $b instanceof strawberryRunnerPostprocessorEntityInterface) {

      if ($a->isActive() && $b->isActive()) {
        return static::hierarchicalSort($a, $b);
      }
      elseif (!$a->isActive()) {
        return -1;
      }
      elseif (!$b->isActive()) {
        return 1;
      }
    }

    return parent::sort($a, $b);
  }

  /**
   * Helper callback for uasort() to sort configuration entities by weight, parent and label.
   */
  public static function hierarchicalSort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    $a_parent = isset($a->parent) ? $a->parent : '';
    $b_parent = isset($b->parent) ? $b->parent : '';

    $a_weight = isset($a->weight) ? $a->weight : 0;
    $b_weight = isset($b->weight) ? $b->weight : 0;
    if ($a_parent == $b->id()) {
      return 1;
    }
    if ($b_parent == $a->id()) {
      return -1;
    }
    if ($a_weight == $b_weight) {
      $a_label = $a->label();
      $b_label = $b->label();
      return strnatcasecmp($a_label, $b_label);
    }
    return ($a_weight < $b_weight) ? -1 : 1;
  }

}
