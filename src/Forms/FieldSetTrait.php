<?php

namespace SilverStripe\Forms;

/**
 * Trait that provides methods and properties necessary for fields that can be rendered as a fieldset or as a div.
 */
trait FieldSetTrait
{
    /**
     * @var string custom HTML tag to render with, e.g. to produce a `<fieldset>`.
     */
    protected $tag = 'div';

    /**
     * @var string Optional description for this set of fields.
     * If the {@link $tag} property is set to use a 'fieldset', this will be
     * rendered as a `<legend>` tag, otherwise its a 'title' attribute.
     */
    protected $legend;

    /**
     * @param string $tag
     * @return $this
     */
    public function setTag($tag)
    {
        $this->tag = $tag;
        return $this;
    }

    /**
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @param string $legend
     * @return $this
     */
    public function setLegend($legend)
    {
        $this->legend = $legend;
        return $this;
    }

    /**
     * @return string
     */
    public function getLegend()
    {
        return $this->legend;
    }
}
