<?php
namespace Pimcore\Document\Tag;

use Pimcore\Bundle\PimcoreBundle\Templating\Model\ViewModelInterface;
use Pimcore\Model\Document\Tag;
use Pimcore\Model\Document\Tag\Area\Info;
use Pimcore\View;

interface TagHandlerInterface
{
    /**
     * Determine if handler strategy supports the tag
     *
     * @param ViewModelInterface|View $view
     * @return bool
     */
    public function supports($view);

    /**
     * Get available areas for an areablock
     *
     * @param Tag\Areablock $tag
     * @param array $options
     *
     * @return array
     */
    public function getAvailableAreablockAreas(Tag\Areablock $tag, array $options);

    /**
     * Render the area frontend
     *
     * @param Info $info
     * @param array $params
     */
    public function renderAreaFrontend(Info $info, array $params);

    /**
     * Render a sub-action (snippet, renderlet)
     *
     * @param ViewModelInterface|View $view
     * @param string $controller
     * @param string $action
     * @param string|null $parent Bundle or module (legacy) name
     * @param array $params
     * @return string
     */
    public function renderAction($view, $controller, $action, $parent = null, array $params = []);
}