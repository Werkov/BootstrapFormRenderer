<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\BootstrapFormRenderer;

use FKS\Components\Forms\Containers\ContainerWithOptions;
use Iterator;
use Nette\Application\UI\Presenter;
use Nette\Forms\Container;
use Nette\Forms\ControlGroup;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\RadioList;
use Nette\Forms\Form;
use Nette\Forms\IControl;
use Nette\Forms\IFormRenderer;
use Nette\Forms\ISubmitterControl;
use Nette\InvalidArgumentException;
use Nette\Iterators\Filter;
use Nette\Latte\Engine;
use Nette\Latte\Macros\FormMacros;
use Nette\Object;
use Nette\Templating\FileTemplate;
use Nette\Templating\Template;
use Nette\Utils\Html;
use NiftyGrid\Components\Button;
use RuntimeException;
use stdClass;

/**
 * Created with twitter bootstrap in mind.
 *
 * <code>
 * $form->setRenderer(new Kdyby\BootstrapFormRenderer\BootstrapRenderer);
 * </code>
 *
 * @author Pavel Ptacek
 * @author Filip Procházka
 * @author Michal Koutný <michal@fykos.cz>  Ported to Bootstrap 3.0.
 */
class BootstrapRenderer extends Object implements IFormRenderer {

    /** @const Suffix to form whole group ID from element ID */
    const PAIR_ID_SUFFIX = '-pair';
    
    /** @const Width in grid blocks of the whole page. */
    const BOOTSTRAP_GRID = 12;

    /**
     * set to false, if you want to display the field errors also as form errors
     * @var bool
     */
    public $errorsAtInputs = TRUE;

    /**
     * Groups that should be rendered first
     */
    public $priorGroups = array();

    /**
     * @var Form
     */
    private $form;

    /**
     * @var Template|stdClass
     */
    private $template;

    /**
     * @var int how many grid blocks does labels/left column occupy
     */
    private $colLeft = 3;

    /**
     * @var int how many grid blocks does textfield/right column occupy
     */
    private $colRight = 6;
    
    /**
     * @var int Width of nested fieldset (in grid blocks)
     */
    private $subWidth = 8;
    /**
     * @var int how many containers are transformed into groups
     */
    private $groupLevel = 0;

    /**
     * @param FileTemplate $template
     */
    public function __construct(FileTemplate $template = NULL) {
        $this->template = $template;
    }

    public function getColLeft() {
        return $this->colLeft;
    }

    public function setColLeft($colLeft) {
        $this->colLeft = $colLeft;
    }

    public function getColRight() {
        return $this->colRight;
    }

    public function setColRight($colRight) {
        $this->colRight = $colRight;
    }
    
    public function getGroupLevel() {
        return $this->groupLevel;
    }

    public function setGroupLevel($groupLevel) {
        $this->groupLevel = $groupLevel;
    }

    
    /**
     * Render the templates
     *
     * @param Form $form
     * @param string $mode
     * @param array $args
     * @return void
     */
    public function render(Form $form, $mode = NULL, $args = NULL) {
        if ($this->template === NULL) {
            if ($presenter = $form->lookup('Nette\Application\UI\Presenter', FALSE)) {
                /** @var Presenter $presenter */
                $this->template = clone $presenter->getTemplate();
            } else {
                $this->template = new FileTemplate();
                $this->template->registerFilter(new Engine());
            }
        }

        if ($this->form !== $form) {
            $this->form = $form;

            // translators
            if ($translator = $this->form->getTranslator()) {
                $this->template->setTranslator($translator);
            }

            // controls placeholders & classes
            foreach ($this->form->getControls() as $control) {
                $this->prepareControl($control);
            }

            $formEl = $form->getElementPrototype();
            if (!($classes = self::getClasses($formEl)) || stripos($classes, 'form-') === FALSE) {
                $formEl->addClass('form-horizontal');
            }
        } elseif ($mode === 'begin') {
            foreach ($this->form->getControls() as $control) {
                /** @var BaseControl $control */
                $control->setOption('rendered', FALSE);
            }
        }

        $this->template->setFile(__DIR__ . '/@form.latte');
        $subColLeft = ceil(self::BOOTSTRAP_GRID * ($this->subWidth - $this->colRight) / $this->subWidth);
        $subColRight = self::BOOTSTRAP_GRID - $subColLeft;
        $subOffset = ($this->colLeft + $this->colRight - $this->subWidth);
        $this->template->setParameters(
                array_fill_keys(array('control', '_control', 'presenter', '_presenter'), NULL) +
                array('_form' => $this->form, 'form' => $this->form, 'renderer' => $this,
                    'colLeft' => $this->colLeft, 'colRight' => $this->colRight,
                    'subColLeft' => $subColLeft, 'subColRight' => $subColRight, 'subOffset' => $subOffset, 'subWidth' => $this->subWidth)
        );

        if ($mode === NULL) {
            if ($args) {
                $this->form->getElementPrototype()->addAttributes($args);
            }
            $this->template->render();
        } elseif ($mode === 'begin') {
            FormMacros::renderFormBegin($this->form, (array) $args);
        } elseif ($mode === 'end') {
            FormMacros::renderFormEnd($this->form);
        } else {

            $attrs = array('input' => array(), 'label' => array());
            foreach ((array) $args as $key => $val) {
                if (stripos($key, 'input-') === 0) {
                    $attrs['input'][substr($key, 6)] = $val;
                } elseif (stripos($key, 'label-') === 0) {
                    $attrs['label'][substr($key, 6)] = $val;
                }
            }

            $this->template->setFile(__DIR__ . '/@parts.latte');
            $this->template->mode = $mode;
            $this->template->attrs = (array) $attrs;
            $this->template->render();
        }
    }

    /**
     * @param BaseControl $control
     */
    private function prepareControl(BaseControl $control) {
        $translator = $this->form->getTranslator();
        $control->setOption('rendered', FALSE);

        if ($control->isRequired()) {
            $control->getLabelPrototype()->addClass('required');
            $control->setOption('required', TRUE);
        }

        $el = $control->getControlPrototype();
        if ($placeholder = $control->getOption('placeholder')) {
            if (!$placeholder instanceof Html && $translator) {
                $placeholder = $translator->translate($placeholder);
            }
            $el->placeholder($placeholder);
        }

        if ($control->controlPrototype->type === 'email' && $control->getOption('input-prepend') === NULL
        ) {
            $control->setOption('input-prepend', '@');
        }

        if ($control instanceof ISubmitterControl) {
            $el->addClass('btn');
        } else {
            $label = $control->labelPrototype;
            if ($control instanceof Checkbox) {
                $label->addClass('checkbox');
            } elseif (!$control instanceof RadioList) {
                $label->addClass('control-label');
            }

            $control->setOption('pairContainer', $pair = Html::el('div'));
            $pair->id = $control->htmlId . self::PAIR_ID_SUFFIX;
            $pair->addClass('control-group');
            if ($control->getOption('required', FALSE)) {
                $pair->addClass('required');
            }
            if ($control->errors) {
                $pair->addClass('error');
            }

            if ($prepend = $control->getOption('input-prepend')) {
                $prepend = Html::el('span', array('class' => 'add-on'))
                        ->{$prepend instanceof Html ? 'add' : 'setText'}($prepend);
                $control->setOption('input-prepend', $prepend);
            }

            if ($append = $control->getOption('input-append')) {
                $append = Html::el('span', array('class' => 'add-on'))
                        ->{$append instanceof Html ? 'add' : 'setText'}($append);
                $control->setOption('input-append', $append);
            }
        }
    }

    /**
     * @return array
     */
    public function findErrors() {
        $formErrors = method_exists($this->form, 'getAllErrors') ? $this->form->getAllErrors() : $this->form->getErrors();

        if (!$formErrors) {
            return array();
        }

        $form = $this->form;
        $translate = function ($errors) use ($form) {
                    if ($translator = $form->getTranslator()) { // If we have translator, translate!
                        foreach ($errors as $key => $val) {
                            $errors[$key] = $translator->translate($val);
                        }
                    }

                    return $errors;
                };

        if (!$this->errorsAtInputs) {
            return $translate($formErrors);
        }

        if (method_exists($this->form, 'getAllErrors')) {
            return $translate($this->form->getErrors());
        }

        foreach ($this->form->getControls() as $control) {
            /** @var BaseControl $control */
            if (!$control->hasErrors()) {
                continue;
            }

            $formErrors = array_diff($formErrors, $control->getErrors());
        }

        return $translate($formErrors);
    }

    /**
     * @throws RuntimeException
     * @return object[]
     */
    public function findGroups() {
        $formGroups = $visitedGroups = array();
        foreach ($this->priorGroups as $i => $group) {
            if (!$group instanceof ControlGroup) {
                if (!$group = $this->form->getGroup($group)) {
                    $groupName = (string) $this->priorGroups[$i];
                    throw new RuntimeException("Form has no group $groupName.");
                }
            }

            $visitedGroups[] = $group;
            if ($group = $this->processGroup($group)) {
                $formGroups[] = $group;
            }
        }

        foreach ($this->form->groups as $group) {
            if (!in_array($group, $visitedGroups, TRUE) && ($group = $this->processGroup($group))) {
                $formGroups[] = $group;
            }
        }

        return $formGroups;
    }

    /**
     * @param Container $container
     * @param boolean $buttons
     * @return Iterator
     */
    public function findControls(Container $container = NULL, $buttons = NULL) {
        $container = $container ? : $this->form;
        return new Filter($container->getControls(), function ($control) use ($buttons) {
                    $control = $control instanceof Filter ? $control->current() : $control;
                    $isButton = $control instanceof Button || $control instanceof ISubmitterControl;
                    return !$control->getOption('rendered') && !$control instanceof HiddenField && (($buttons === TRUE && $isButton) || ($buttons === FALSE && !$isButton) || $buttons === NULL);
                });
    }
    
    public function groupsFromContainers(Container $container = null, $level = 0) {
        $root = !$container;
        $container = $container ? : $this->form;
        
        $groupLabel = (!$root && $container instanceof ContainerWithOptions) ? $container->getOption('label', $container->getName()) : null;
        $groupDescription = ($container instanceof ContainerWithOptions) ? $container->getOption('description') : null;        
        
        $groupAttrs = Html::el();
        $groupAttrs->setName(NULL);
        /** @var Html $groupAttrs */
        $offset = ($this->colLeft + $this->colRight - $this->subWidth);
        $offset = ($offset > 0) ? 'col-lg-offset-'.$offset : '';
        //$attrs = $level == 2 ? array('class' => 'well well-sm col-lg-' . $this->subWidth . ' ' . $offset) : array();
        $attrs = array();
        $groupAttrs->attrs += array_diff_key($attrs, array_fill_keys(array(
            'container', 'label', 'description', 'visual' // these are not attributes
                        ), NULL));
        
        $groupControls = array();
        foreach($container->getComponents() as $component) {
            if($component instanceof Container) {
                if($level < $this->getGroupLevel()) {
                    $groupControls[] = $this->groupsFromContainers($component, $level + 1);
                } else {
                    foreach($this->findControls($component) as $control) {
                        $groupControls[] = $control;
                    }
                }
            } else {
                $groupControls[] = $component;
            }
        }
        // fake group
        return (object) (array(
            'controls' => $groupControls,
            'root' => $root,
            'label' => $groupLabel,
            'description' => $groupDescription,
            'attrs' => $groupAttrs,
            'level' => $level));
    }

    /**
     * @internal
     * @param ControlGroup $group
     * @return object
     */
    public function processGroup(ControlGroup $group) {
        if (!$group->getOption('visual') || !$group->getControls()) {
            return NULL;
        }

        $groupLabel = $group->getOption('label');
        $groupDescription = $group->getOption('description');

        // If we have translator, translate!
        if ($translator = $this->form->getTranslator()) {
            if (!$groupLabel instanceof Html) {
                $groupLabel = $translator->translate($groupLabel);
            }
            if (!$groupDescription instanceof Html) {
                $groupDescription = $translator->translate($groupDescription);
            }
        }

        $controls = array_filter($group->getControls(), function (BaseControl $control) {
                    return !$control->getOption('rendered') && !$control instanceof HiddenField;
                });

        if (!$controls) {
            return NULL; // do not render empty groups
        }

        $groupAttrs = $group->getOption('container', Html::el())->setName(NULL);
        /** @var Html $groupAttrs */
        $groupAttrs->attrs += array_diff_key($group->getOptions(), array_fill_keys(array(
            'container', 'label', 'description', 'visual' // these are not attributes
                        ), NULL));

        // fake group
        return (object) (array(
            'controls' => $controls,
            'root' => false,
            'level' => 0,
            'label' => $groupLabel,
            'description' => $groupDescription,
            'attrs' => $groupAttrs,
                ) + $group->getOptions());
    }

    /**
     *  @internal
     * @param BaseControl $control
     * @return string
     */
    public static function getControlName(BaseControl $control) {
        return $control->lookupPath('Nette\Forms\Form');
    }

    /**
     *  @internal
     * @param BaseControl $control
     * @return Html
     */
    public static function getControlDescription(BaseControl $control) {
        if (!$desc = $control->getOption('description')) {
            return Html::el();
        }

        // If we have translator, translate!
        if (!$desc instanceof Html && ($translator = $control->form->getTranslator())) {
            $desc = $translator->translate($desc); // wtf?
        }

        // create element
        return Html::el('p', array('class' => 'help-block'))
                        ->{$desc instanceof Html ? 'add' : 'setText'}($desc);
    }

    /**
     *  @internal
     * @param BaseControl $control
     * @return Html
     */
    public function getControlError(BaseControl $control) {
        if (!($errors = $control->getErrors()) || !$this->errorsAtInputs) {
            return Html::el();
        }
        $error = reset($errors);

        // If we have translator, translate!
        if (!$error instanceof Html && ($translator = $control->form->getTranslator())) {
            $error = $translator->translate($error); // wtf?
        }

        // create element
        return Html::el('p', array('class' => 'help-inline'))
                        ->{$error instanceof Html ? 'add' : 'setText'}($error);
    }

    /**
     *  @internal
     * @param BaseControl $control
     * @return string
     */
    public static function getControlTemplate(BaseControl $control) {
        return $control->getOption('template');
    }

    /**
     *  @internal
     * @param IControl $control
     * @return bool
     */
    public static function isButton(IControl $control) {
        return $control instanceof Button;
    }

    /**
     *  @internal
     * @param IControl $control
     * @return bool
     */
    public static function isSubmitButton(IControl $control = NULL) {
        return $control instanceof ISubmitterControl;
    }

    /**
     *  @internal
     * @param IControl $control
     * @return bool
     */
    public static function isCheckbox(IControl $control) {
        return $control instanceof Checkbox;
    }

    /**
     *  @internal
     * @param IControl $control
     * @return bool
     */
    public static function isRadioList(IControl $control) {
        return $control instanceof RadioList;
    }

    /**
     *  @internal
     * @param IControl $control
     * @return bool
     */
    public static function isCheckboxList(IControl $control) {
        foreach (array('Nette\Forms\Controls\\', 'Kdyby\Forms\Controls\\', '',) as $ns) {
            if (class_exists($class = $ns . 'CheckboxList', FALSE) && $control instanceof $class) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * @internal
     * @param RadioList $control
     * @return bool
     */
    public static function getRadioListItems(RadioList $control) {
        $items = array();
        foreach ($control->items as $key => $value) {
            $el = $control->getControl($key);
            if ($el->getName() === 'input') {
                $items[$key] = $radio = (object) array(
                            'input' => $el,
                            'label' => $cap = $control->getLabel(NULL, $key),
                            'caption' => $cap->getText(),
                );
            } else {
                $items[$key] = $radio = (object) array(
                            'input' => $el[0],
                            'label' => $el[1],
                            'caption' => $el[1]->getText(),
                );
            }
        }

        return $items;
    }

    /**
     * @internal
     * @param BaseControl $control
     * @throws InvalidArgumentException
     * @return bool
     */
    public static function getCheckboxListItems(BaseControl $control) {
        $items = array();
        foreach ($control->items as $key => $value) {
            $el = $control->getControl($key);
            $el[1]->addClass('checkbox')->addClass('inline');

            $items[$key] = $check = (object) array(
                        'input' => $el[0],
                        'label' => $el[1],
                        'caption' => $el[1]->getText(),
            );

            $check->html = clone $check->label;
            $check->html->insert(0, $check->input);
        }

        return $items;
    }

    /**
     * @param BaseControl $control
     * @return Html
     */
    public static function getLabelBody(BaseControl $control) {
        $label = $control->getLabel();
        $label->setName(NULL);
        return $label;
    }

    /**
     * @param BaseControl $control
     * @param string $class
     * @return bool
     */
    public static function controlHasClass(BaseControl $control, $class) {
        $classes = explode(' ', self::getClasses($control->controlPrototype));
        return in_array($class, $classes, TRUE);
    }

    /**
     * @param Html $_this
     * @param array $attrs
     * @return Html
     */
    public static function mergeAttrs(Html $_this = NULL, array $attrs) {
        if ($_this === NULL) {
            return Html::el();
        }

        $_this->attrs = array_merge_recursive($_this->attrs, $attrs);
        return $_this;
    }

    /**
     * @param Html $el
     * @return bool
     */
    private static function getClasses(Html $el) {
        if (is_array($el->class)) {
            $classes = array_filter(array_merge(array_keys($el->class), $el->class), 'is_string');
            return implode(' ', $classes);
        }
        return $el->class;
    }

}
