<?php

namespace Drupal\ebms_review\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\ebms_review\Entity\Packet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * AJAX callback for starred packets settings.
 */
class PacketStar extends ControllerBase {

  /**
   * Conversion from structures into rendered output.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): PacketStar {
    // Instantiates this form class.
    $instance = parent::create($container);
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * Modify the packet's starred setting and return the star's new render array.
   */
  public function update(int $packet_id, int $flag) {
    $packet = Packet::load($packet_id);
    $packet->set('starred', $flag);
    $packet->save();
    $star = [
      '#theme' => 'packet_star',
      '#id' => $packet_id,
      '#starred' => $flag,
    ];
    $html = $this->renderer->render($star);
    return new Response($html);
  }
}
