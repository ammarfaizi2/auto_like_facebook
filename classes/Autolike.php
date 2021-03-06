<?php

use Facebook\Facebook;

class Autolike
{
	private $fb;

	private $token;

	private $jobs;

	public function __construct(Facebook $fb)
	{
		if (file_exists('lock.tmp')) {
			$lock = (int) file_get_contents('lock.tmp');
			if ($lock >= time()) {
				die("Locked until ".date('Y-m-d H:i:s', $lock)."\nReason : (#17) User request limit reached\n\n");
			}
		}
		$this->fb = $fb;
	}

	public function run()
	{
		$this->tokenizer();
		$this->getJobs();
	}

	private function tokenizer($force = false)
	{
		$a = file_exists(token_file) ? json_decode(file_get_contents(token_file), true) : null;
		if (!$force && is_array($a) && isset($a['token'], $a['expired']) && $a['expired'] > time()) {
			$this->token = $a['token'];
		} else {
			$f = file_get_contents(config.'/token_generation.txt');
			$f = $this->fb->goTo($f, [CURLOPT_HEADER => 2]);
			$f = substr($f['out'], 0, $f['info']['header_size']);
			if (preg_match('/https:\/\/www.instagram.com\/accounts\/signup\/\?#access_token=(.*)&expires_in=(.*)&/Usi', $f, $matches)) {
				$this->token = $a['token'] = $matches[1];
				$a['expired'] = time() + $matches[2] - 120;
				$a['updated_at'] = date('Y-m-d H:i:s');
				file_put_contents(token_file, json_encode($a, 128));
			} else {
				die;
			}
		}
	}

	private function getJobs()
	{
		foreach (explode("\n", file_get_contents(target)) as $userId) {
			$userId = explode('#', $userId);
			$userId = trim($userId[0]);
			if (! empty($userId)) {
				print 'Getting "'.$userId.'" information...'.PHP_EOL;
				print 'Checking latest post...'.PHP_EOL;
				$latestPost = $this->getLatestPost($userId);
				print 'Latest post checked '.json_encode($latestPost).PHP_EOL;
				$localPost = $this->getLocalPost($userId);
				if ($latestPost !== $localPost['latest_post']) {
					print 'New post... Doing action...'.PHP_EOL;
					$this->action($latestPost, $localPost, $userId);
					print PHP_EOL.PHP_EOL;
				} else {
					print 'Old post... Skip...'.PHP_EOL;
				}
			}
		}
	}

	private function action($latestPost, $localPost, $userId)
	{
		if ($latestPost !== false) {
			$status = $this->fb->reaction($latestPost, 'LIKE');
			$localPost['failed'] = !$status;
			$localPost['latest_post'] = $latestPost;
			$localPost['updated_at'] = date('Y-m-d H:i:s');
			$localPost['queue']++;
			file_put_contents(queue.'/target/'.$userId.'.txt', json_encode($localPost, 128));
			return $status;
		}
	}

	private function getLatestPost($userId)
	{
		$a = $this->fb->goTo('https://graph.facebook.com/'.$userId.'/feed?limit=1&fields=id&access_token='.$this->token);
		if ($a['info']['http_code'] !== 200) {
			$this->tokenizer(true);
			$a = $this->fb->goTo('https://graph.facebook.com/'.$userId.'/feed?limit=1&fields=id&access_token='.$this->token);
		}
		if ($a['info']['http_code'] === 200) {
			$a = json_decode($a['out'], true);
			if (isset($a['data'][0]['id'])) {
				$a = explode('_', $a['data'][0]['id']);
				return isset($a[1]) ? $a[1] : false;
			}
		}
		if (preg_match('/limit reached/Usi', $a['out'])) {
			file_put_contents('lock.tmp', time() + (600));
			die;
		}
		return false;
	}

	private function getLocalPost($userId)
	{
		is_dir(queue) or mkdir(queue);
		is_dir(queue.'/target') or mkdir(queue.'/target');
		if (file_exists(queue.'/target/'.$userId.'.txt')) {
			return json_decode(file_get_contents(queue.'/target/'.$userId.'.txt'), true);
		}
		file_put_contents(queue.'/target/'.$userId.'.txt', 
			json_encode(
				$r = [
					'updated_at' => date('Y-m-d H:i:s'),
					'latest_post' => false,
					'failed' => false,
					'queue' => 0
				],
				128
			)
		);
		return $r;
	}
}

