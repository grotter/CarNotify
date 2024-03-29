<?php
	
	date_default_timezone_set('America/Los_Angeles');

	class Notify {
		protected $_debug = false;
		protected $_credentials = false;
		protected $_loc = false;
		protected $_dayOfWeek = false;
		protected $_streets = false;
		protected $_files = array();
		
		protected $_urlPrefix = 'https://utility.calacademy.org/grotter/carloc/';
		protected $_streetsUrl = 'https://www.ocf.berkeley.edu/~grotter/prius/json/streets.json';

		public function __construct ($car) {
			if (php_sapi_name() != 'cli') die('not allowed');

			global $argv;

			if (isset($argv[2])) {
				$this->_debug = ($argv[2] == 'debug');
			}

			require_once('credentials.php');
			if (!isset($credentials[$car])) die;
			$this->_credentials = $credentials[$car];

			$this->_files = array(
				'png' => dirname(__FILE__) . '/current-location-' . $this->_credentials['vehicleId'] . '.png',
				'data' => dirname(__FILE__) . '/sent-notifications-' . $this->_credentials['vehicleId'] . '.js'
			);

			// street override config
			$this->_streets = $this->getData($this->_streetsUrl);

			// get location
			$url = $this->_urlPrefix;
			$url .= '?' . http_build_query(array(
				'vehicleId' => $this->_credentials['vehicleId'],
				'token' => $this->_credentials['token']
			));

			$this->_loc = $this->getData($url);
			if (!$this->_loc) return false;

			if (isset($this->_loc->error)) {
				if ($this->_debug) print_r($this->_loc);
				return;
			}

			// quit if parked less than two hours
			$diff = $this->getSecondsFrom($this->_loc->timestamp);

			if ($diff === false) {
				if ($this->_debug) {
					echo "Invalid location data.\n";
					print_r($this->_loc);
				}

				return;
			}

			if ($this->_debug) {
				echo "Parked hours…\n";
				echo (abs($diff) / (60 * 60));
				echo "\n";
			}

			if (abs($diff) < 2 * 60 * 60) return;

			// first query
			$this->getAddress();
		}

		public function getAddress () {
			$url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/';
			$url .= $this->_loc->longitude . ',' . $this->_loc->latitude;
			$url .= '.json';

			$url .= '?' . http_build_query(array(
				'access_token' => $this->_credentials['mapbox_token']
			));

			$data = $this->getData($url);

			if (is_array($data->features)) {
				foreach ($data->features as $feature) {
					if ($feature->place_type[0] == 'address') {
						if (isset($feature->text) && is_array($this->_streets)) {
							
							// override with customization
							foreach ($this->_streets as $street) {
								if (strpos($feature->text, $street->needle) === 0) {
									$this->_dayOfWeek = $street->sweepDay;
								}	
							}

						}

						break;
					}
				}
			}

			// next query
			$this->getStreetSweeping();
		}

		protected function _getOverride () {
			$data = $this->getData($this->_urlPrefix . 'override/' . $this->_credentials['vehicleId'] . '.json');

			// not found
			if (!$data) return false;

			// check location match
			if ($data->longitude != $this->_loc->longitude) return false;
			if ($data->latitude != $this->_loc->latitude) return false;

			// check if expired
			if (time() > $data->override->cleaning_time_start) return false;

			return $data->override->cleaning_time_start;
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

			// make sure we have accurate data for exceptions
			if ($this->_dayOfWeek) {
				foreach ($data->rows as $obj) {
					if (!isset($obj->properties)) continue;
					if (!isset($obj->properties->cleaning_time_start)) continue;

					// a known cleaning day of the week
					if (gmdate('N', $obj->properties->cleaning_time_start) == $this->_dayOfWeek) {
						$row = $obj;
						break;
					}
				}
			}

			// override
			$override = $this->_getOverride();

			if (is_numeric($override)) {
				// reset if time matches override
				foreach ($data->rows as $obj) {
					if (!isset($obj->properties)) continue;
					if (!isset($obj->properties->cleaning_time_start)) continue;

					if ($obj->properties->cleaning_time_start == $override) {
						$row = $obj;
						break;
					}
				}
			}

			if (!isset($row->properties)) return;
			if (!isset($row->properties->cleaning_time_start)) return;

			$untilSweep = $this->getSecondsFrom($row->properties->cleaning_time_start, true);
			if ($untilSweep === false) return;

			// already past
			if ($untilSweep <= 0) {
				echo "Already past. Quitting…\n";
				return;
			}
			
			$hours = ($untilSweep / 3600);

			if ($this->_debug) {
				echo "Hours until sweep on ". gmdate('g:ia \o\n l, F jS', $row->properties->cleaning_time_start) ."…\n";
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

			return $this->getData($url, true);
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
			$cmd .= 'mutt -s "' . $this->_credentials['name'] . '" ' . join(',', $contacts);

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

		public function getData ($url, $raw = false) {
			$ch = curl_init();
			
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			
			$result = curl_exec($ch);

			if ($this->_debug) {
				echo $url . "\n";
				echo $result . "\n";

				if ($result === false) {
					echo curl_error($ch) . "\n";
				}
			}

			curl_close($ch);

			if ($result === false) return false;
			if ($raw) return $result;

			$decoded = json_decode($result);
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
		$foo = new Notify($argv[1]);	
	}
	
?>
