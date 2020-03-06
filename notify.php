<?php

	class Notify {
		protected $_credentials = false;
		protected $_loc = false;

		public function __construct () {
			if (php_sapi_name() != 'cli') die('not allowed');
			
			require_once('credentials.php');
			$this->_credentials = $credentials;

			// get location
			$url = 'https://www.ocf.berkeley.edu/~grotter/prius/json/';
			$url .= '?' . http_build_query($this->_credentials);

			$this->_loc = $this->getData($url);
			
			if (isset($this->_loc->error)) return;

			// quit if parked less than three hours
			$diff = $this->getSecondsFrom($this->_loc->timestamp);
			
			if ($diff === false) return;
			if ($diff < 3 * 60 * 60) return;

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

			$untilSweep = $this->getSecondsFrom($row->properties->cleaning_time_start);
			if (!is_numeric($untilSweep)) return;
			
			$hours = abs($untilSweep / 3600);

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
			$sentDataFile = 'sent-notifications.js';

			$sent = @file_get_contents($sentDataFile);
			$sentData = array();

			if ($sent !== false) {
				$sentData = json_decode($sent, true);

				if (!is_null($sentData)) {
					// check if sent in last 12 hours
					if (is_numeric($sentData[$level])) {
						$diff = time() - $sentData[$level];

						if ($diff < 12 * 60 * 60) {
							echo "Already sent {$level} notification.\n";
							return;
						}
					}
				}
			}

			$date = gmdate('g:ia \o\n l, F jS', $sweepTime);
			$cmd = 'echo "I might be parked in a street sweeping zone. Street sweeping starts at ' . $date . '." | ';
			
			require_once('contacts.php');
			$cmd .= 'mutt -s "Grey Pantera" ' . join(',', $contacts);

			// try creating map attachment
			$fileName = 'current-location.png';
			$map = $this->getMap();
			
			if ($map !== false) {
				$file = file_put_contents($fileName, $map);

				if ($file !== false && file_exists($fileName)) {
					$cmd .= ' -a ' . $fileName;
				}
			}

			echo "Attempting to send {$level} notification.\n";
			shell_exec($cmd);

			// save send time
			$sentData[$level] = time();
			file_put_contents($sentDataFile, json_encode($sentData));
		}

		public function getData ($url) {
			$json = file_get_contents($url);
			if ($json === false) return false;
			
			$decoded = json_decode($json);
			if (is_null($decoded)) return false;

			return $decoded;
		}

		public function getSecondsFrom ($val) {
			if (is_numeric($val)) {
				$time = $val;
			} else {
				$time = strtotime($val);
				if (!$time) return false;
			}

			return (time() - $time);
		}
	}

	if (php_sapi_name() == 'cli') {
		$foo = new Notify();	
	}
	
?>
