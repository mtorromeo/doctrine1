<?php
/* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

/**
 * Doctrine_Record_Listener_Chain
 * this class represents a chain of different listeners,
 * useful for having multiple listeners listening the events at the same time
 *
 * @package    Doctrine
 * @subpackage Record
 * @author     Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       www.doctrine-project.org
 * @since      1.0
 * @version    $Revision$
 */
class Doctrine_Record_Listener_Chain extends Doctrine_Access implements Doctrine_Record_Listener_Interface
{
    /**
     * @var array $listeners        an array containing all listeners
     */
    protected $listeners = [];

    /**
     * @var array $options        an array containing chain options
     */
    protected $options = ['disabled' => false];

    /**
     * setOption
     * sets an option in order to allow flexible listener chaining
     *
     * @param mixed $name  the name of the option to set
     * @param mixed $value the value of the option
     *
     * @return void
     */
    public function setOption($name, $value = null)
    {
        if (is_array($name)) {
            $this->options = Doctrine_Lib::arrayDeepMerge($this->options, $name);
        } else {
            $this->options[$name] = $value;
        }
    }

    /**
     * getOption
     * returns the value of given option
     *
     * @param  string $name the name of the option
     * @return mixed        the value of given option
     */
    public function getOption($name)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }

        return null;
    }

    /**
     * Get array of configured options
     *
     * @return array $options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * add
     * adds a listener to the chain of listeners
     *
     * @param  object $listener
     * @param  string $name
     * @return void
     */
    public function add($listener, $name = null): void
    {
        if (!($listener instanceof Doctrine_Record_Listener_Interface)
            && !($listener instanceof Doctrine_Overloadable)
        ) {
            throw new Doctrine_EventListener_Exception("Couldn't add eventlistener. Record listeners should implement either Doctrine_Record_Listener_Interface or Doctrine_Overloadable");
        }
        if ($name === null) {
            $this->listeners[] = $listener;
        } else {
            $this->listeners[$name] = $listener;
        }
    }

    /**
     * returns a Doctrine_Record_Listener on success
     * and null on failure
     *
     * @param  mixed $key
     * @return mixed
     */
    public function get($key)
    {
        if (!isset($this->listeners[$key])) {
            return null;
        }
        return $this->listeners[$key];
    }

    /**
     * set
     *
     * @param  mixed                    $key
     * @param  Doctrine_Record_Listener $listener listener to be added
     * @return void
     */
    public function set($key, $listener)
    {
        $this->listeners[$key] = $listener;
    }

    /**
     * @return void
     */
    public function preSerialize(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preSerialize', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preSerialize', $disabled))) {
                    $listener->preSerialize($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postSerialize(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postSerialize', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postSerialize', $disabled))) {
                    $listener->postSerialize($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preUnserialize(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preUnserialize', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preUnserialize', $disabled))) {
                    $listener->preUnserialize($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postUnserialize(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postUnserialize', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postUnserialize', $disabled))) {
                    $listener->postUnserialize($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preDqlSelect(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preDqlSelect', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preDqlSelect', $disabled))) {
                    $listener->preDqlSelect($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preSave(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preSave', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preSave', $disabled))) {
                    $listener->preSave($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postSave(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postSave', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postSave', $disabled))) {
                    $listener->postSave($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preDqlDelete(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preDqlDelete', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preDqlDelete', $disabled))) {
                    $listener->preDqlDelete($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preDelete(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preDelete', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preDelete', $disabled))) {
                    $listener->preDelete($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postDelete(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postDelete', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postDelete', $disabled))) {
                    $listener->postDelete($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preDqlUpdate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preDqlUpdate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preDqlUpdate', $disabled))) {
                    $listener->preDqlUpdate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preUpdate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preUpdate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preUpdate', $disabled))) {
                    $listener->preUpdate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postUpdate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postUpdate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postUpdate', $disabled))) {
                    $listener->postUpdate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preInsert(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preInsert', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preInsert', $disabled))) {
                    $listener->preInsert($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postInsert(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postInsert', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postInsert', $disabled))) {
                    $listener->postInsert($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preHydrate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preHydrate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preHydrate', $disabled))) {
                    $listener->preHydrate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postHydrate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postHydrate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postHydrate', $disabled))) {
                    $listener->postHydrate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function preValidate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('preValidate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('preValidate', $disabled))) {
                    $listener->preValidate($event);
                }
            }
        }
    }

    /**
     * @return void
     */
    public function postValidate(Doctrine_Event $event)
    {
        $disabled = $this->getOption('disabled');

        if ($disabled !== true && !(is_array($disabled) && in_array('postValidate', $disabled))) {
            foreach ($this->listeners as $listener) {
                $disabled = $listener->getOption('disabled');

                if ($disabled !== true && !(is_array($disabled) && in_array('postValidate', $disabled))) {
                    $listener->postValidate($event);
                }
            }
        }
    }
}
