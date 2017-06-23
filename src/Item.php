<?php
/**
 * Contains the Menu Item class.
 *
 * @author      Lavary
 * @author      Attila Fulop
 * @license     MIT
 * @since       2017-06-16
 *
 */

namespace Konekt\Menu;

use Konekt\Menu\Traits\HasAttributes;
use Request;

class Item
{
    use HasAttributes;

    /** @var string The name (or id) of the menu item */
    public $name;

    /** @var string */
    public $title;

    /** @var Item   Parent item, if any */
    public $parent;

    /** @var bool   Flag for active state */
    public $isActive = false;

    /** @var array  Extra information attached to the menu item */
    protected $data = [];

    /** @var Menu   Reference to the menu holding the item */
    protected $menu;

    /** @var string URL pattern to match (if no exact match) */
    private $activeUrlPattern;

    private $reserved = ['route', 'action', 'url', 'prefix', 'parent'];

    /**
     * Class constructor
     *
     * @param  Menu   $menu
     * @param  string $name
     * @param  string $title
     * @param  array  $options
     */
    public function __construct(Menu $menu, $name, $title, $options)
    {
        $this->menu       = $menu;
        $this->name       = $name;
        $this->title      = $title;
        $this->attributes = array_except($options, $this->reserved);
        $this->parent     = array_get($options, 'parent', null);


        $path       = array_only($options, array('url', 'route', 'action'));
        $this->link = new Link($path, $this->menu->config->activeClass);

        // Activate the item if items's url matches the request URI
        if ($this->menu->config->autoActivate && $this->currentUrlMatches()) {
            $this->activate();
        }
    }

    /**
     * Creates a sub Item
     *
     * @param  string       $name
     * @param  string       $title
     * @param  string|array $options
     *
     * @return Item
     */
    public function addSubItem($name, $title, $options = [])
    {
        $options = is_array($options) ? $options : ['url' => $options];
        $options['parent'] = $this;

        return $this->menu->addItem($name, $title, $options);
    }


    /**
     * Generate URL for link
     *
     * @return string|null
     */
    public function url()
    {
        if (!$this->link) {
            return null;
        }

        return $this->link->url();
    }

    /**
     * Prepends text or html to the item
     *
     * @return \Konekt\Menu\Item
     */
    public function prepend($html)
    {
        $this->title = $html . $this->title;

        return $this;
    }

    /**
     * Appends text or html to the item
     *
     * @return \Konekt\Menu\Item
     */
    public function append($html)
    {
        $this->title .= $html;

        return $this;
    }

    /**
     * Returns whether the item has any children
     *
     * @return boolean
     */
    public function hasChildren()
    {
        return (bool) $this->children()->count();
    }

    /**
     * Returns childeren of the item
     *
     * @return \Konekt\Menu\ItemCollection
     */
    public function children()
    {
        return $this->menu->items->filter(function($item) {
            return $item->hasParent() && $item->parent->name == $this->name;
        });
    }

    /**
     * Returns whether the item has a parent
     *
     * @return bool
     */
    public function hasParent()
    {
        return (bool)$this->parent;
    }

    /**
     * Returns all childeren of the item
     *
     * @return \Konekt\Menu\ItemCollection
     */
    public function all()
    {
        return $this->menu->whereParent($this->id, true);
    }

    /**
     * Sets the item as active
     */
    public function activate()
    {
        if ($this->menu->config->activeElement == 'item') {
            $this->setToActive();
        } else {
            $this->link->activate();
        }

        // If parent activation is enabled:
        if ($this->menu->config->activateParents) {
            // Moving up through the parent nodes, activating them as well.
            if ($this->parent) {
                $this->parent->activate();
            }
        }
    }

    /**
     * Sets the url pattern that if matched, sets the link to active
     *
     * @param string $pattern   Eg.: 'articles/*
     *
     * @return $this
     */
    public function activateOnUrl($pattern)
    {
        $this->activeUrlPattern = $pattern;

        return $this;
    }

    /**
     * Set or get items's meta data
     *
     * @param array $args
     *
     * @return mixed
     */
    public function data(...$args)
    {
        if (isset($args[0]) && is_array($args[0])) {
            $this->data = array_merge($this->data, array_change_key_case($args[0]));

            // Cascade data to item's children if cascade_data option is enabled
            if ($this->menu->config->cascadeData) {
                $this->cascade_data($args);
            }
            return $this;
        } elseif (isset($args[0]) && isset($args[1])) {
            $this->data[strtolower($args[0])] = $args[1];

            // Cascade data to item's children if cascade_data option is enabled
            if ($this->menu->conf['cascade_data']) {
                $this->cascade_data($args);
            }
            return $this;
        } elseif (isset($args[0])) {
            return isset($this->data[$args[0]]) ? $this->data[$args[0]] : null;
        }

        return $this->data;
    }

    /**
     * Returns whether metadata with given key exists
     *
     * @param $key
     *
     * @return bool
     */
    public function hasData($key)
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Cascade data to children
     *
     * @param  array $args
     *
     * @return bool
     */
    public function cascade_data($args = array())
    {
        if ( ! $this->hasChildren()) {
            return false;
        }

        if (count($args) >= 2) {
            $this->children()->data($args[0], $args[1]);
        } else {
            $this->children()->data($args[0]);
        }
    }

    /**
     * Check if property exists either in the class or the meta collection
     *
     * @param  string $property
     *
     * @return bool
     */
    public function hasProperty($property)
    {
        return
            property_exists($this, $property)
            ||
            $this->hasAttribute($property)
            ||
            $this->hasData($property);
    }


    /**
     * Search in meta data if a property doesn't exist otherwise return the property
     *
     * @param  string
     *
     * @return string
     */
    public function __get($prop)
    {
        if (property_exists($this, $prop)) {
            return $this->$prop;
        }

        return $this->data($prop);
    }

    /**
     * Make the item active
     *
     * @return Item
     */
    protected function setToActive()
    {
        $this->attributes['class'] = Utils::addHtmlClass(
            array_get($this->attributes, 'class'),
            $this->menu->config->activeClass
        );
        $this->isActive = true;

        return $this;
    }

    /**
     * Returns whether the current URL matches this link's URL
     *
     * @return bool
     */
    protected function currentUrlMatches()
    {
        if ($this->activeUrlPattern) {
            $pattern = ltrim(preg_replace('/\/\*/', '(/.*)?', $this->activeUrlPattern), '/');
            return preg_match("@^{$pattern}\z@", Request::path());
        }

        return $this->url() == Request::url();
    }

}
