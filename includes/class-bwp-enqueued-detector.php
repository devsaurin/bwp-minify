<?php
/**
 * Copyright (c) 2014 Khang Minh <http://betterwp.net>
 * @license http://www.gnu.org/licenses/gpl.html GNU GENERAL PUBLIC LICENSE VERSION 3.0 OR LATER
 */

/**
 * Class BWP_Enqueued_Detector
 * @author Khang Minh <contact@betterwp.net>
 * @since BWP Minify 1.3.0
 * @package BWP Minify
 */
class BWP_Enqueued_Detector
{
	private $_domain = '';

	private $_options = array();

	private $_log_key = '';

	private $_logs = array();

	private $_db_logs = array();

	private $_scripts = array();

	private $_styles = array();

	private $_groups = array();

	private $_version = '1.0.0';

	public function __construct($options, $domain)
	{
		$this->_options = $options;
		$this->_domain = $domain;

		$this->_init();
	}

	/**
	 * Detects an enqueued script
	 *
	 * This function is called whenever a script is being added to a Minify
	 * Group and it will get necessary data to store in the detector's log.
	 * Stored data include: `handle`, `url (processed src)`, `dependencies`,
	 * `position`, `group`
	 *
	 * @param $handle string handle of the script being detected
	 * @param $item array data of the script being detected
	 * @param $groups array the current list of Minify Groups. This is the
	 *        list at the time of detection.
	 * @return void
	 */
	public function detect_script($handle, $item)
	{
		$group_handle = $item['group'];

		$log_data = array(
			'handle'   => $handle,
			'src'      => $item['src'],
			'depend'   => $item['depend'],
			'position' => $this->_get_position($item),
			'group'    => $item['group']
		);

		$this->_log('script', $log_data);
	}

	/**
	 * Detects an enqueued style
	 *
	 * This function is called whenever a style is being added to a Minify
	 * Group and it will get necessary data to store in the detector's log.
	 * Stored data include: `handle`, `url (processed src)`, `dependencies`,
	 * `position`, `media type`, `group`
	 *
	 * @param $handle string handle of the style being detected
	 * @param $item array data of the style being detected
	 * @param $groups array the current list of Minify Groups. This is the
	 *        list at the time of detection.
	 * @return void
	 */
	public function detect_style($handle, $item)
	{
		$group_handle = $item['group'];

		$log_data = array(
			'handle'   => $handle,
			'src'      => $item['src'],
			'depend'   => $item['depend'],
			'position' => $this->_get_position($item),
			'group'    => $item['group'],
			'media'    => $item['media']
		);

		$this->_log('style', $log_data);
	}

	/**
	 * Detects a group that is going to be served
	 *
	 * @param $group_handle string handle of the group being detected
	 * @param $string string the original string that contains paths to
	 *        files as a comma-separated list
	 * @param $group_type string either 'script' or 'style'
	 * @return void
	 */
	public function detect_group($group_handle, $string, $group_type)
	{
		$hash = md5($string . $this->_version);

		$this->_log('group', array(
			'handle' => $group_handle . '-' . substr($hash, 0, 15),
			'string' => $string,
			'type'   => $group_type,
			'hash'   => $hash
		));
	}

	public function get_group($group_handle, $from_db = false)
	{
		$groups = $this->_groups;

		foreach ($groups as $handle => $group)
		{
			if ($group_handle == $handle)
				return $group;
		}

		return false;
	}

	public function get_detected_scripts($from_db = false)
	{
		if ($from_db)
			return isset($this->_db_logs['scripts'])
				? $this->_db_logs['scripts']
				: array();
		else
			return $this->_scripts;
	}

	public function get_detected_styles($from_db = false)
	{
		if ($from_db)
			return isset($this->_db_logs['styles'])
				? $this->_db_logs['styles']
				: array();
		else
			return $this->_styles;
	}

	public function get_detected_groups($from_db = false)
	{
		if ($from_db)
			return isset($this->_db_logs['groups'])
				? $this->_db_logs['groups']
				: array();
		else
			return $this->_groups;
	}

	public function get_version()
	{
		return $this->_version;
	}

	public function show_detected_scripts()
	{
		$headings = array(
			'handle'   => __('Handle', $this->_domain),
			'position' => __('Position', $this->_domain),
			'src'      => __('Script src', $this->_domain),
			'action'   => __('Actions', $this->_domain)
		);

		$this->_show_log('script', $headings);
	}

	public function show_detected_styles()
	{
		$headings = array(
			'handle'   => __('Handle', $this->_domain),
			'position' => __('Position', $this->_domain),
			'media'    => __('Media', $this->_domain),
			'src'      => __('Style src', $this->_domain),
			'action'   => __('Actions', $this->_domain)
		);

		$this->_show_log('style', $headings);
	}

	public function clear_logs($type = 'enqueue')
	{
		if ('enqueue' === $type || 'all' == $type)
		{
			$this->_scripts = array();
			$this->_styles = array();
		}

		if ('group' == $type || 'all' == $type)
		{
			$this->_groups = array();
		}

		$this->commit_logs();
	}

	/**
	 * Updates display text of a file's position in the log
	 *
	 * When a group is moved to a different position all files contained in
	 * that group are moved too so we have to update their position in the log
	 * accordingly.
	 *
	 * @param $type string type of group either 'script' or 'style'
	 * @param $group_handle string the moved group
	 * @param $new_position string new position of the moved group
	 * @return void
	 */
	public function update_position($type, $group_handle, $new_position)
	{
		if ('script' == $type)
			$log = &$this->_scripts;
		else
			$log = &$this->_styles;

		foreach ($log as &$item)
		{
			if ($group_handle == $item['group'])
			{
				$item['position'] = $new_position;
				$item['position'] = $this->_get_position($log);
			}
		}
	}

	public function set_log($key)
	{
		$logs = get_option($key);

		$this->_log_key = $key;
		$this->_logs = !empty($logs) ? (array) $logs : array();
		$this->_db_logs = $this->_logs;

		$this->_prepare_logs();
	}

	/**
	 * Combines all logs and overwrites logs in db
	 *
	 * @return void
	 */
	public function commit_logs()
	{
		$this->_logs = array(
			'scripts' => $this->_scripts,
			'styles'  => $this->_styles,
			'groups'  => $this->_groups
		);

		update_option($this->_log_key, $this->_logs);
	}

	private function _show_log($type, $headings)
	{
?>
		<thead>
			<tr>
<?php
		foreach ($headings as $heading) :
?>
				<th><span class="bwp-text"><?php echo $heading; ?></span></th>
<?php
		endforeach;
?>
			</tr>
		</thead>
		<tbody>
<?php
		$log = 'script' == $type ? $this->_scripts : $this->_styles;

		$actions = 'script' == $type
			? array('header'   => __('move to header', $this->_domain),
					'footer'   => __('move to footer', $this->_domain),
					'direct'   => __('print separately', $this->_domain),
					'ignore'   => __('ignore', $this->_domain),
					'oblivion' => __('forget', $this->_domain))
			: array('style_direct'   => __('print separately', $this->_domain),
					'style_ignore'   => __('ignore', $this->_domain),
					'style_oblivion' => __('forget', $this->_domain));

		if (0 < sizeof($log)) :
		foreach ($log as $item) :
?>
			<tr>
<?php
			foreach ($headings as $key => $heading) :
				if ('action' == $key)
					continue;

				if ('src' == $key)
					$value = '<input readonly="readonly" type="text" value="'
						. esc_attr($item[$key])
						. '" />';
				else
					$value = esc_html($item[$key]);
?>
				<td><?php echo $value ?></td>
<?php
			endforeach;
?>
				<td style="width: 50px">
					<a href="#" class="action-toggle-handle"><?php _e('select', $this->_domain); ?></a>
					<div class="action-handles">
						<span class="data-handle"><?php esc_html_e($item['handle']); ?></span>
<?php
			foreach ($actions as $position => $action) :
?>
						| <a href="#" class="action-handle"
							data-position="<?php echo $position; ?>"><?php echo $action; ?></a>
<?php
			endforeach;
?>
					</div>
				</td>
			</tr>
<?php
		endforeach;
		else :
?>
			<tr>
				<td colspan="5">
					<?php _e('No enqueued files detected.<br /><br />'
					. 'Please try visiting a few pages on your site '
					. 'and then refresh this page.<br /><br />'
					. 'You should clear this list once in a while '
					. 'to get rid of files that are no longer being used '
					. 'as this is not done automatically.', $this->_domain); ?>
				</td>
			</tr>
<?php
		endif;
?>
		</tbody>
<?php
	}

	/**
	 * Gets enqueued file's user-friendly position text
	 *
	 * Most of the time this function will rely on the expected position of a
	 * file (registered via `wp_enqueue_` functions or via custom positioning),
	 * but it might as well return a dynamic position due to a forceful
	 * positioning of one of its dependencies.
	 *
	 * @see $this->_update_position()
	 *
	 * @access private
	 * @param $item array data of the enqueued file
	 * @return string user-friendly position text
	 */
	private function _get_position($item)
	{
		$position = $item['position'];
		if ('oblivion' != $position)
		{
			$position = 'header' == $position // header or footer or footer{n}
				? __('header', $this->_domain)
				: __('footer', $this->_domain);
		}

		$min = $item['min']; // minify needed
		$wp = $item['wp']; // minify but print separately

		if ($min)
		{
			// separated or combined and minified
			return $wp
				? sprintf(__('separated in %s', $this->_domain), $position)
				: sprintf(__('minified in %s', $this->_domain), $position);
		}
		else
		{
			// ignored or forgotten
			return 'oblivion' == $position
				? __('forgotten', $this->_domain)
				: sprintf(__('ignored in %s', $this->_domain), $position);
		}
	}

	private function _log($type, $data)
	{
		if ('script' == $type)
			$this->_scripts[$data['handle']] = $data;
		else if ('style' == $type)
			$this->_styles[$data['handle']] = $data;
		else if ('group' == $type)
			$this->_groups[$data['handle']] = $data;
	}

	private function _prepare_logs()
	{
		$logs = $this->_logs;

		$this->_scripts = isset($logs['scripts']) && is_array($logs['scripts'])
			? $logs['scripts']
			: array();

		$this->_styles = isset($logs['styles']) && is_array($logs['styles'])
			? $logs['styles']
			: array();

		$this->_groups = isset($logs['groups']) && is_array($logs['groups'])
			? $logs['groups']
			: array();
	}

	private function _setup_crons()
	{
	}

	private function _register_hooks()
	{
		add_action('bwp_minify_moved_group', array($this, 'update_position'), 10, 3);
		add_action('bwp_minify_processed_style', array($this, 'detect_style'), 10, 2);
		add_action('bwp_minify_processed_script', array($this, 'detect_script'), 10, 2);
	}

	private function _init()
	{
		$this->_register_hooks();
		$this->_setup_crons();
	}
}
