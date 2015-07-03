<?php

namespace Croogo\Blocks\Model\Table;

use Cake\ORM\Query;
use Croogo\Core\Model\Table\CroogoTable;

/**
 * Region
 *
 * @category Blocks.Model
 * @package  Croogo.Blocks.Model
 * @version  1.0
 * @author   Fahad Ibnay Heylaal <contact@fahad19.com>
 * @license  http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link     http://www.croogo.org
 */
class RegionsTable extends CroogoTable {

/**
 * Validation
 *
 * @var array
 * @access public
 */
	public $validate = array(
		'title' => array(
			'rule' => array('minLength', 1),
			'message' => 'Title cannot be empty.',
		),
		'alias' => array(
			'isUnique' => array(
				'rule' => 'isUnique',
				'message' => 'This alias has already been taken.',
			),
			'minLength' => array(
				'rule' => array('minLength', 1),
				'message' => 'Alias cannot be empty.',
			),
		),
	);

/**
 * Filter search fields
 *
 * @var array
 * @access public
 */
	public $filterArgs = array(
		'title' => array('type' => 'like', 'field' => array('Region.title'))
	);

/**
 * Display fields for this model
 *
 * @var array
 */
	protected $_displayFields = array(
		'id',
		'title',
		'alias',
	);

/**
 * Find methods
 */
	public $findMethods = array(
		'active' => true,
	);

	public function initialize(array $config) {
		parent::initialize($config);
		$this->entityClass('Croogo/Blocks.Region');
		$this->addAssociations([
			'hasMany' => [
				'Blocks' => [
					'className' => 'Blocks.Blocks',
					'foreignKey' => 'region_id',
					'dependent' => false,
					'limit' => 3,
				],
			],
		]);

		$this->addBehavior('Search.Searchable');
		/* TODO: Enable after behaviors have been updated to 3.x
		$this->addBehavior('Croogo.Cached', [
			'groups' => [
				'blocks',
			],
		]);
		$this->addBehavior('Croogo.Trackable');
		*/
	}

/**
 * Find Regions currently in use
 */
	public function findActive(Query $query) {
		return $query->where([
			'block_count >' => 0
		])->select([
			'id',
			'alias'
		])->applyOptions([
			'cache' => [
				'name' => 'regions',
				'config' => 'croogo_blocks',
			],
		]);
	}

}
