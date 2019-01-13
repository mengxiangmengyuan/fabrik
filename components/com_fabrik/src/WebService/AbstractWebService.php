<?php
/**
 * Abstract web service class
 *
 * @package     Joomla
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

namespace Fabrik\Component\Fabrik\Site\WebService;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Text;
use Joomla\CMS\Factory;
use Fabrik\Component\Fabrik\Site\Model\ListModel;
use Joomla\String\StringHelper;
use Fabrik\Helpers\Worker;
use Fabrik\Helpers\ArrayHelper as FArrayHelper;
use Fabrik\Helpers\StringHelper as FStringHelper;

/**
 * Abstract web service class
 *
 * @package     Joomla
 * @subpackage  Fabrik
 * @since       3.0.5
 */
abstract class AbstractWebService
{
	/**
	 * AbstractWebService instances container.
	 *
	 * @since  3.0.5
	 *
	 * @var    AbstractWebService[]
	 */
	protected static $instances = array();

	/**
	 * @var array
	 * @since 4.0
	 */
	protected $options;

	/**
	 * Query the web service to get the data
	 *
	 * @param   string $method     method to call at web service (soap only)
	 * @param   array  $options    key value filters to send to web service to filter the data
	 * @param   string $startPoint startPoint of actual data, if soap this is an xpath expression,
	 *                             otherwise its a key.key2.key3 string to traverse the returned data to arrive at the data to map to the fabrik list
	 * @param   string $result     result method name - soap only, if not set then "$method . 'Result' will be used.
	 *
	 * @return    array    series of objects which can then be bound to the list using storeLocally()
	 *
	 * @since 4.0
	 */
	public abstract function get($method, $options = array(), $startPoint = null, $result = null);

	/**
	 * Get web service instance
	 *
	 * @param   array $options initial state options
	 *
	 * @throws \Exception
	 *
	 * @return  AbstractWebService
	 *
	 * @since 4.0
	 */
	public static function getInstance($options = array())
	{
		// Sanitize the database connector options.
		$options['driver']   = (isset($options['driver'])) ? preg_replace('/[^A-Z0-9_\.-]/i', '', $options['driver']) : 'soap';
		$options['endpoint'] = (isset($options['endpoint'])) ? $options['endpoint'] : null;

		// Get the options signature for the database connector.
		$signature = md5(serialize($options));

		// If we already have a database connector instance for these options then just use that.
		if (empty(self::$instances[$signature]))
		{
			// Derive the class name from the driver.
			$class = sprintf('Joomla\\Component\\Fabrik\\Site\\WebService\\%sWebService', StringHelper::ucfirst($options['driver']));

			// If the class still doesn't exist we have nothing left to do but throw an exception.  We did our best.
			if (!class_exists($class))
			{
				throw new \Exception(Text::sprintf('JLIB_DATABASE_ERROR_LOAD_DATABASE_DRIVER', $options['driver']));
			}
			// Create our new FabrikWebService connector based on the options given.
			try
			{
				$instance = new $class($options);
			}
			catch (\Exception $e)
			{
				throw new \Exception(Text::sprintf('JLIB_DATABASE_ERROR_CONNECT_DATABASE', $e->getMessage()));
			}

			// Set the new connector to the global instances based on signature.
			self::$instances[$signature] = $instance;
		}

		return self::$instances[$signature];
	}

	/**
	 * Set the map which defines which webservice fields are mapped to
	 * which Fabrik fields
	 *
	 * @param   array $map service map
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */
	public function setMap($map)
	{
		/* How to map the data from the web service to a Fabrik list:

		 $this->map = array(
		 array('from' => '{EventId}', 'to' => $fk),
		array('from' => '{Genre}', 'to' => 'genres'),
		array('from' => '{DoorOpen}', 'to' => 'Event_date'),
		array('from' => '{DoorClosed}', 'to' => 'Event_date_end'),
		array('from' => '{DoorClosed}', 'to' => 'Event_publish_end'),
		array('from' => '{IsFreeEntrance}', 'to' => 'ticket_cost', 'value' => 0, 'match' => 'true'),
		array('from' => '{AvailabilityStatus}', 'to' => 'ticket_cost', 'value' => 3, 'match' => 'false'),
		array('from' => '{ShortText}', 'to' => 'Event_description_short'),
		array('from' => '{Title} ({SubTitle})', 'to' => 'Event_title'),
		array('from' => '{Price}', 'to' => 'Event_price_presale', 'match' =>
			'foreach($d->Price as $p) { if($p->TicketType == \'Normaal\') { return $p->Price;}} return false;', 'eval' => true),
		array('from' => '{Price}', 'to' => 'Event_price_door',
			'match' => 'foreach($d->Price as $p) { if($p->TicketType == \'Dagkassa\') { return $p->Price;}} return false;', 'eval' => true)
		); */
		$this->map = $map;
	}

	/**
	 * Map web service data to Fabrik fields
	 *
	 * @param   array  $datas web service data
	 * @param   string $fk    foreign key
	 *
	 * @return  array mapped data
	 *
	 * @since 4.0
	 */
	public function map($datas, $fk)
	{
		$return = array();
		$w      = new Worker;

		foreach ($datas as $data)
		{
			$row = array();

			foreach ($this->map as $map)
			{
				$to          = $map['to'];
				$map['from'] = $w->parseMessageForPlaceHolder($map['from'], $data, false);

				if (FArrayHelper::getValue($map, 'match', '') !== '')
				{
					if (FArrayHelper::getValue($map, 'eval') == 1)
					{
						$res = eval($map['match']);

						if ($res !== false)
						{
							$row[$to] = $res;
						}
					}
					else
					{
						if ($map['match'] == $map['from'])
						{
							$row[$to] = $map['value'];
						}
					}
				}
				else
				{
					$row[$to] = $map['from'];
				}
			}

			$return[] = $row;
		}

		return $return;
	}

	/**
	 * Store the data obtained from get() in a list
	 *
	 * @param   ListModel $listModel list model to store the data in
	 * @param   array  $data      data obtained from get()
	 * @param   string $fk        foreign key to map records in $data to the list models data.
	 * @param   bool   $update    should existing matched rows be updated or not?
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */
	public function storeLocally(ListModel $listModel, $data, $fk, $update)
	{
		$data      = $this->map($data, $fk);
		$item      = $listModel->getTable();
		$formModel = $listModel->getFormModel();
		$db        = $listModel->getDb();
		$item      = $listModel->getTable();

		$query = $db->getQuery(true);
		$query->select($item->db_primary_key . ' AS id, ' . $fk)->from($item->db_table_name);
		$db->setQuery($query);
		$ids = $db->loadObjectList($fk);
		$formModel->getGroupsHiarachy();
		$this->updateCount = 0;
		$this->addedCount  = 0;
		$primaryKey        = FStringHelper::shortColName($item->db_primary_key);
		$primaryKey        = str_replace("`", "", $primaryKey);

		foreach ($data as $row)
		{
			foreach ($row as $k => $v)
			{
				$elementModel = $formModel->getElement($k, true);
				$row[$k]      = $elementModel->fromXMLFormat($v);
			}

			$pk = '';

			if (array_key_exists($row[$fk], $ids) && $row[$fk] != '')
			{
				$pk = $ids[$row[$fk]]->id;
			}

			if (!$update && $pk !== '')
			{
				continue;
			}

			if ($pk == '')
			{
				$this->addedCount++;
			}
			else
			{
				$this->updateCount++;
			}


			$row[$primaryKey] = $pk;

			$listModel->storeRow($row, $pk);

		}
	}

	/**
	 * Parse the filter values into driver type
	 *
	 * @param   string $val  value
	 * @param   string $type type
	 *
	 * @return  string
	 *
	 * @since 4.0
	 */
	public function getFilterValue($val, $type)
	{
		switch ($type)
		{
			case 'bool':
				$val = (bool) $val;
				break;
			case 'date':
				$d   = Factory::getDate($val);
				$val = $d->toISO8601();
				break;
			case 'text':
				break;
		}

		return $val;
	}
}
