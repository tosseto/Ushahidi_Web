<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model for reported Incidents
 *
 *
 * PHP version 5
 * LICENSE: This source file is subject to LGPL license
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/copyleft/lesser.html
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi - http://source.ushahididev.com
 * @module     Incident Model
 * @copyright  Ushahidi - http://www.ushahidi.com
 * @license    http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License (LGPL)
 */

class Incident_Model extends ORM
{
	protected $has_many = array('category' => 'incident_category', 'media', 'verify', 'comment',
		'rating', 'alert' => 'alert_sent', 'incident_lang', 'form_response','cluster' => 'cluster_incident');
	protected $has_one = array('location','incident_person','user','message','twitter','form');
	protected $belongs_to = array('sharing');

	// Database table name
	protected $table_name = 'incident';

	// Prevents cached items from being reloaded
	protected $reload_on_wakeup   = FALSE;

	static function get_active_categories()
	{
		// Get all active categories
		$categories = array();
		foreach (ORM::factory('category')
			->where('category_visible', '1')
			->find_all() as $category)
		{
			// Create a list of all categories
			$categories[$category->id] = array($category->category_title, $category->category_color);
		}
		return $categories;
	}

	/*
	* get the total number of reports
	* @param approved - Only count approved reports if true
	*/
	public static function get_total_reports($approved=false)
	{
		if($approved)
		{
			$count = ORM::factory('incident')->where('incident_active', '1')->count_all();
		}else{
			$count = ORM::factory('incident')->count_all();
		}

		return $count;
	}

	/*
	* get the total number of verified or unverified reports
	* @param verified - Only count verified reports if true, unverified if false
	*/
	public static function get_total_reports_by_verified($verified=false)
	{
		if($verified)
		{
			$count = ORM::factory('incident')->where('incident_verified', '1')->where('incident_active', '1')->count_all();
		}else{
			$count = ORM::factory('incident')->where('incident_verified', '0')->where('incident_active', '1')->count_all();
		}

		return $count;
	}

	/*
	* get the total number of verified or unverified reports
	* @param approved - Oldest approved report timestamp if true (oldest overall if false)
	*/
	public static function get_oldest_report_timestamp($approved=true)
	{
		if($approved)
		{
			$result = ORM::factory('incident')->where('incident_active', '1')->orderby(array('incident_date'=>'ASC'))->find_all(1,0);
		}else{
			$result = ORM::factory('incident')->where('incident_active', '0')->orderby(array('incident_date'=>'ASC'))->find_all(1,0);
		}

		foreach($result as $report)
		{
			return strtotime($report->incident_date);
		}
	}

	private static function category_graph_text($sql, $category)
	{
		$db = new Database();
		$query = $db->query($sql);
		$graph_data = array();
		$graph = ", \"".  $category[0] ."\": { label: '". str_replace("'","",$category[0]) ."', ";
		foreach ( $query as $month_count )
		{
			array_push($graph_data, "[" . $month_count->time * 1000 . ", " . $month_count->number . "]");
		}
		$graph .= "data: [". join($graph_data, ",") . "], ";
		$graph .= "color: '#". $category[1] ."' ";
		$graph .= " } ";
		return $graph;
	}

	static function get_incidents_by_interval($interval='month',$start_date=NULL,$end_date=NULL,$active='true',$media_type=NULL)
	{
		// Table Prefix
		$table_prefix = Kohana::config('database.default.table_prefix');

		// get graph data
		// could not use DB query builder. It does not support parentheses yet
		$db = new Database();

		$select_date_text = "DATE_FORMAT(incident_date, '%Y-%m-01')";
		$groupby_date_text = "DATE_FORMAT(incident_date, '%Y%m')";
		if ($interval == 'day') {
			$select_date_text = "DATE_FORMAT(incident_date, '%Y-%m-%d')";
			$groupby_date_text = "DATE_FORMAT(incident_date, '%Y%m%d')";
		} elseif ($interval == 'hour') {
			$select_date_text = "DATE_FORMAT(incident_date, '%Y-%m-%d %H:%M')";
			$groupby_date_text = "DATE_FORMAT(incident_date, '%Y%m%d%H')";
		} elseif ($interval == 'week') {
			$select_date_text = "STR_TO_DATE(CONCAT(CAST(YEARWEEK(incident_date) AS CHAR), ' Sunday'), '%X%V %W')";
			$groupby_date_text = "YEARWEEK(incident_date)";
		}

		$date_filter = "";
		if ($start_date) {
			$date_filter .= ' AND incident_date >= "' . $start_date . '"';
		}
		if ($end_date) {
			$date_filter .= ' AND incident_date <= "' . $end_date . '"';
		}

		$active_filter = '1';
		if ($active == 'all' || $active == 'false') {
			$active_filter = '0,1';
		}

		$joins = '';
		$general_filter = '';
		if (isset($media_type) && is_numeric($media_type)) {
			$joins = 'INNER JOIN '.$table_prefix.'media AS m ON m.incident_id = i.id';
			$general_filter = ' AND m.media_type IN ('. $media_type  .')';
		}

		$graph_data = array();
		$all_graphs = array();

		$all_graphs['0'] = array();
		$all_graphs['0']['label'] = 'All Categories';
		$query_text = 'SELECT UNIX_TIMESTAMP(' . $select_date_text . ') AS time,
					   COUNT(*) AS number
					   FROM '.$table_prefix.'incident AS i ' . $joins . '
					   WHERE incident_active IN (' . $active_filter .')' .
		$general_filter .'
					   GROUP BY ' . $groupby_date_text;
		$query = $db->query($query_text);
		$all_graphs['0']['data'] = array();
		foreach ( $query as $month_count )
		{
			array_push($all_graphs['0']['data'],
				array($month_count->time * 1000, $month_count->number));
		}
		$all_graphs['0']['color'] = '#990000';

		$query_text = 'SELECT category_id, category_title, category_color, UNIX_TIMESTAMP(' . $select_date_text . ')
							AS time, COUNT(*) AS number
								FROM '.$table_prefix.'incident AS i
							INNER JOIN '.$table_prefix.'incident_category AS ic ON ic.incident_id = i.id
							INNER JOIN '.$table_prefix.'category AS c ON ic.category_id = c.id
							' . $joins . '
							WHERE incident_active IN (' . $active_filter . ')
								  ' . $general_filter . '
							GROUP BY ' . $groupby_date_text . ', category_id ';
		$query = $db->query($query_text);
		foreach ( $query as $month_count )
		{
			$category_id = $month_count->category_id;
			if (!isset($all_graphs[$category_id]))
			{
				$all_graphs[$category_id] = array();
				$all_graphs[$category_id]['label'] = $month_count->category_title;
				$all_graphs[$category_id]['color'] = '#'. $month_count->category_color;
				$all_graphs[$category_id]['data'] = array();
			}
			array_push($all_graphs[$category_id]['data'],
				array($month_count->time * 1000, $month_count->number));
		}
		$graphs = json_encode($all_graphs);
		return $graphs;
	}

	/*
	* get the number of reports by date for dashboard chart
	*/
	public static function get_number_reports_by_date($range=NULL)
	{
		// Table Prefix
		$table_prefix = Kohana::config('database.default.table_prefix');
		
		$db = new Database();
		
		if ($range == NULL)
		{
			$sql = 'SELECT COUNT(id) as count, DATE(incident_date) as date, MONTH(incident_date) as month, DAY(incident_date) as day, YEAR(incident_date) as year FROM '.$table_prefix.'incident GROUP BY date ORDER BY incident_date ASC';
		}else{
			$sql = 'SELECT COUNT(id) as count, DATE(incident_date) as date, MONTH(incident_date) as month, DAY(incident_date) as day, YEAR(incident_date) as year FROM '.$table_prefix.'incident WHERE incident_date >= DATE_SUB(CURDATE(), INTERVAL '.mysql_escape_string($range).' DAY) GROUP BY date ORDER BY incident_date ASC';
		}
		
		$query = $db->query($sql);
		$result = $query->result_array(FALSE);
		
		$array = array();
		foreach ($result AS $row)
		{
			$timestamp = mktime(0,0,0,$row['month'],$row['day'],$row['year'])*1000;
			$array["$timestamp"] = $row['count'];
		}

		return $array;
	}

	/*
	* return an array of the dates of all approved incidents
	*/
	static function get_incident_dates()
	{
		//$incidents = ORM::factory('incident')->where('incident_active',1)->incident_date->find_all();
		$incidents = ORM::factory('incident')->where('incident_active',1)->select_list('id', 'incident_date');
		$array = array();
		foreach ($incidents as $id => $incident_date)
		{
			$array[] = $incident_date;
		}
		return $array;
	}
	public static function get_incident_reports($filter,$order_string,$sql_offset)
	{
		$incidents = ORM::factory('incident')
				->join('location', 'incident.location_id', 'location.id','INNER')
//				->join('media', 'incident.id', 'media.incident_id','LEFT')
				->where($filter)
				->orderby('incident_date', $order_string)
				->find_all((int) Kohana::config('settings.items_per_page_admin'), $sql_offset);

		return $incidents;
	}
	public static function get_incident_reports_filter_category($filter,$order_string,$sql_offset)
	{
                
		$incidents = ORM::factory('incident')
				->join('location', 'incident.location_id', 'location.id','INNER')
				->join('incident_category', 'incident.id', 'incident_category.incident_id','LEFT')
				->where($filter)
				->orderby('incident_date', $order_string)
				->find_all((int) Kohana::config('settings.items_per_page_admin'), $sql_offset);

		return $incidents;
	}
	public static function get_incident_persons($filter)
	{
		$temp_incident_persons = ORM::factory('incident_person')
				->where($filter)
				->find_all();
		$incident_persons = array();
		foreach($temp_incident_persons as $incident_person){
			$incident_persons[$incident_person->incident_id]['id'] = $incident_person->id;
			$incident_persons[$incident_person->incident_id]['person_first'] = $incident_person->person_first;
			$incident_persons[$incident_person->incident_id]['person_last'] = $incident_person->person_last;
		}
		return $incident_persons;
	}
	public static function get_incident_messages($filter)
	{
		$temp_incident_message = ORM::factory('message')
				->where($filter)
				->find_all();
		$incident_message = array();
		foreach($temp_incident_message as $message){
			$incident_message[$message->incident_id]['message_from'] = $message->message_from;
		}
		return $incident_message;
	}
	public static function get_incident_incident_langs($filter)
	{
		$temp_incident_incident_lang = ORM::factory('incident_lang')
				->where($filter)
				->find_all();
		$incident_incident_langs = array();
		foreach($temp_incident_incident_lang as $incident_lang){
			$incident_incident_langs[$incident_lang->incident_id]['id'] = $incident_lang->id;
		}
		return $incident_incident_langs;
	}
	public static function get_incident_incident_categories($filter)
	{
		$temp_incident_incident_categories = ORM::factory('incident_category')
				->join('category','category.id','incident_category.category_id','LEFT OUTER')
				->where($filter)
				->find_all();
		$incident_incident_categories = array();
		foreach($temp_incident_incident_categories as $incident_category){
			if (!array_key_exists($incident_category->incident_id,$incident_incident_categories)){
				$incident_incident_categories[$incident_category->incident_id]['category_title'] = array();
			}
			$incident_incident_categories[$incident_category->incident_id]['category_title'][] = $incident_category->category->category_title;
		}
		return $incident_incident_categories;
	}
}
