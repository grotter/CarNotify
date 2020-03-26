<?php
	
	date_default_timezone_set('America/Los_Angeles');

	class Notify {
		protected $_debug = false;
		protected $_credentials = false;
		protected $_loc = false;
		protected $_files = array();

		public function __construct () {
			if (php_sapi_name() != 'cli') die('not allowed');

			global $argv;

			if (isset($argv[1])) {
				$this->_debug = ($argv[1] == 'debug');
			}

			require_once('credentials.php');
			$this->_credentials = $credentials;

			$this->_files = array(
				'png' => dirname(__FILE__) . '/current-location.png',
				'data' => dirname(__FILE__) . '/sent-notifications.js'
			);

			// get location
			$url = 'https://www.ocf.berkeley.edu/~grotter/prius/json/';
			$url .= '?' . http_build_query($this->_credentials);

			$this->_loc = $this->getData($url);
			
			if (isset($this->_loc->error)) {
				if ($this->_debug) print_r($this->_loc);
				return;
			}

			// quit if parked less than two hours
			$diff = $this->getSecondsFrom($this->_loc->timestamp);

			if ($this->_debug) {
				echo "Parked hours…\n";
				echo (abs($diff) / (60 * 60));
				echo "\n";
			}

			if ($diff === false) return;
			if (abs($diff) < 2 * 60 * 60) return;

			// street sweeping
			$this->getStreetSweeping();
		}

		public function getStreetSweeping () {
			$url = 'https://api.xtreet.com/roads2/getnearesttolatlng/';
			$url .= '?' . http_build_query(array(
				'latitude' => $this->_loc->latitude,
				'longitude' => $this->_loc->longitude
			));

			$data = $this->getData($url);

			if (!is_array($data->rows)) return;
			
			$row = $data->rows[0];
			if (!isset($row->properties)) return;
			if (!isset($row->properties->cleaning_time_start)) return;

			$untilSweep = $this->getSecondsFrom($row->properties->cleaning_time_start, true);
			if ($untilSweep === false) return;

			// already past
			if ($untilSweep <= 0) return;
			
			$hours = ($untilSweep / 3600);

			if ($this->_debug) {
				echo "Hours until sweep (". gmdate('g:ia \o\n l, F jS', $row->properties->cleaning_time_start) .")…\n";
				echo $hours;
				echo "\n";
			}

			if ($hours < 12) {
				if ($hours < 1.1) {
					$this->sendNotification($row->properties->cleaning_time_start, 'last');
				} else {
					$this->sendNotification($row->properties->cleaning_time_start, 'first');	
				}
			} else {
				echo "No notification sent.\n";
			}
		}

		public function getMap () {
			$longlat = $this->_loc->longitude . ',' . $this->_loc->latitude;  

			$url = 'https://api.mapbox.com/styles/v1/mapbox/light-v10/static/pin-s+5555ff(' . $longlat . ')/' . $longlat . ',15/300x200@2x';
			
			$url .= '?' . http_build_query(array(
				'access_token' => $this->_credentials['mapbox_token'],
				'attribution' => 'false',
				'logo' => 'false'
			));

			return file_get_contents($url);
		}

		public function sendNotification ($sweepTime, $level) {
			$sent = @file_get_contents($this->_files['data']);
			$sentData = array();

			$currentLocation = $this->_loc->latitude . ',' . $this->_loc->longitude;

			if ($sent !== false) {
				// previous data present
				$sentData = json_decode($sent, true);

				if (is_null($sentData)) {
					// decode failed, reset to empty array
					$sentData = array();
				} else {
					// only send first notification once every 12 hours
					$first = $sentData['first'];

					if ($level == 'first' && is_numeric($first)) {
						$lastSent = $this->getSecondsFrom($first);

						if ($lastSent !== false) {
							$hoursDiff = abs($lastSent) / 3600;

							if ($hoursDiff < 12) {
								if ($this->_debug) {
									echo "Already sent first notification {$hoursDiff} hours ago.\n";
								}

								return;	
							}
						}
					}
				}
			}

			$date = gmdate('g:ia \o\n l, F jS', $sweepTime);
			$cmd = 'echo "I might be parked in a street sweeping zone. It looks like street sweeping starts at ' . $date . '." | ';
			
			require_once('contacts.php');
			$cmd .= 'mutt -s "Grey Pantera" ' . join(',', $contacts);

			// try creating map attachment
			$map = $this->getMap();
			
			if ($map !== false) {
				$file = file_put_contents($this->_files['png'], $map);

				if ($file !== false && file_exists($this->_files['png'])) {
					$cmd .= ' -a ' . $this->_files['png'];
				}
			}

			echo "Attempting to send {$level} notification.\n";
			shell_exec($cmd);

			// save send time
			$sentData[$level] = time();
			$sentData['latlong'] = $currentLocation;

			file_put_contents($this->_files['data'], json_encode($sentData));
		}

		public function getData ($url) {
			$json = file_get_contents($url);
			if ($json === false) return false;
			
			$decoded = json_decode($json);
			if (is_null($decoded)) return false;

			return $decoded;
		}

		public function getSecondsFrom ($val, $offset = false) {
			if (is_string($val)) {
				$val = strtotime($val);
				if ($val === false) return false;
			}

			$val = intval($val);
			if ($val === 0) return false;

			$now = new DateTime();
			$diff = $val - $now->getTimestamp();

			if ($offset) {
				$diff -= $now->getOffset();
			}

			return $diff;
		}
	}

	if (php_sapi_name() == 'cli') {
		$foo = new Notify();	
	}
	
?>
