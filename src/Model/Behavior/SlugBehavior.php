<?php
namespace App\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\Event\Event;
use Cake\Datasource\EntityInterface;
use Cake\Database\Exception;

class SlugBehavior extends Behavior
{

    /**
     * Default config
     *
     * @var array
     */
    protected $_defaultConfig = [
        'slug',
    ];

    /**
     * Default replacement string
     *
     * @var string
     */
    protected $_defaultReplacement = '-';

    /**
     * Default field to create slug
     *
     * @var string
     */
    protected $_defaultField = 'title';

    /**
     * Default finder method
     *
     * @var string
     */
    protected $_defaultFinder = 'list';

    /**
     * {@inheritdoc}
     */
    public function beforeSave(Event $event, EntityInterface $entity)
    {
        if (!empty($this->_config)) {
            foreach ($this->_config as $slug => $config) {
                if (!is_array($config)) {
                    $slug = $config;
                }

                if (!isset($this->_config[$slug]['field'])) {
                    $this->_config[$slug]['field'] = $this->_defaultField;
                }

                if ($this->_table->hasField($this->_config[$slug]['field'])) {
                    $schema = $this->_table->getSchema()->getColumn($slug);

                    if ($schema['type'] == 'string') {
                        if (!isset($this->_config[$slug]['replacement'])) {
                            $this->_config[$slug]['replacement'] = $this->_defaultReplacement;
                        }

                        if (!isset($this->_config[$slug]['length']) || $this->_config[$slug]['length'] > $schema['length']) {
                            $this->_config[$slug]['length'] = $schema['length'];
                        }

                        if (!isset($this->_config[$slug]['finder'])) {
                            $this->_config[$slug]['finder'] = $this->_defaultFinder;
                        }

                        $entity->{$slug} = $this->createSlug($entity->{$this->_config[$slug]['field']}, $slug);
                    } else {
                        throw new FieldTypeException(__d('slug', 'Field should be string type.'));
                    }
                } else {
                    throw new FieldException(__d('slug', 'Cannot find field in schema.'));
                }
            }
        }
    }

    /**
     * Create unique slug
     *
     * @param string $slug String to slug
     * @param string $field Slug field name
     * @throws Exception
     * @return string|mixed
     */
    public function createSlug($slug, $field)
    {
        if ((mb_strlen($this->_config[$field]['replacement']) + 1) < $this->_config[$field]['length']) {
            $slugs = $this->_table->find($this->_config[$field]['finder'], [
                'valueField' => $field,
            ])->where([
                'OR' => [
                    $this->_table->getAlias() . '.' . $field => $slug,
                    $this->_table->getAlias() . '.' . $field . ' REGEXP' => $a = '^' . $slug . $this->_config[$field]['replacement'] . '[1-9]+[0-9]*$',
                ],
            ])->order([
                $this->_table->getAlias() . '.' . $field => 'ASC',
            ])->toArray();

            // Slug is just numbers
            if (preg_match('/^[0-9]+$/', $slug)) {
                $numbers = preg_grep('/^[0-9]+$/', $slugs);

                sort($numbers);

                $slug = end($numbers);

                $slug++;
            }

            // Cut from left side
            if ($length = mb_strlen($replace = preg_replace('/\s+/', $this->_config[$field]['replacement'], $slug)) > $this->_config[$field]['length']) {
                $slug = mb_substr($replace, ($length - $this->_config[$field]['length'] - 1));
            }

            $slug = preg_replace('/\s+/', $this->_config[$field]['replacement'], preg_replace('/' . preg_quote($this->_config[$field]['replacement']) . '$/', '', trim(mb_substr($slug, 0, $this->_config[$field]['length']))));

            if (in_array($slug, $slugs)) {
                $list = preg_grep('/^' . preg_replace('/' . preg_quote($this->_config[$field]['replacement']) . '([1-9]+[0-9]*)$/', $this->_config[$field]['replacement'], $slug) . '/', $slugs);

                preg_match('/^(.*)' . preg_quote($this->_config[$field]['replacement']) . '([1-9]+[0-9]*)$/', end($list), $matches);

                if (empty($matches)) {
                    $increment = 1;
                } else {
                    if (isset($matches[2])) {
                        $increment = $matches[2] += 1;
                    } else {
                        throw new IncrementException(__d('slug', 'Cannot create next suffix because matches are empty.'));
                    }
                }

                if (mb_strlen($slug . $this->_config[$field]['replacement'] . $increment) <= $this->_config[$field]['length']) {
                    $string = $slug;
                } elseif (mb_strlen(mb_substr($slug, 0, -mb_strlen($increment))) + mb_strlen($this->_config[$field]['replacement'] . $increment) <= $this->_config[$field]['length']) {
                    $string = mb_substr($slug, 0, (-mb_strlen($increment) + 1));
                } else {
                    $string = mb_substr($slug, 0, -(mb_strlen($this->_config[$field]['replacement'] . $increment)));
                }

                if (mb_strlen($string) > 0) {
                    $slug = $string . $this->_config[$field]['replacement'] . $increment;

                    // Refresh slugs list
                    $slugs = array_merge($slugs, $this->_table->find($this->_config[$field]['finder'], [
                        'valueField' => $field,
                    ])->where([
                        'OR' => [
                            $this->_table->getAlias() . '.' . $field => $slug,
                            $this->_table->getAlias() . '.' . $field . ' REGEXP' => $a = '^' . $slug . $this->_config[$field]['replacement'] . '[1-9]+[0-9]*$',
                        ],
                    ])->order([
                        $this->_table->getAlias() . '.' . $field => 'ASC',
                    ])->toArray());

                    if (in_array($slug, $slugs)) {
                        return self::createSlug($slug, $field);
                    }
                } else {
                    throw new LengthException(__d('slug', 'Cannot create slug because there are no available names.'));
                }
            }

            return $slug;
        } else {
            throw new LimitException(__d('slug', 'Length limit is to short.'));
        }
    }
}