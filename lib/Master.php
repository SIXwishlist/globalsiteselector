<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\GlobalSiteSelector;

use Firebase\JWT\JWT;
use OC\HintException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Security\ICrypto;

/**
 * Class Master
 *
 * Handle all operations in master mode to redirect the users to their login server
 *
 * @package OCA\GlobalSiteSelector
 */
class Master {

	/** @var GlobalSiteSelector */
	private $gss;

	/** @var ICrypto */
	private $crypto;

	/** @var Lookup */
	private $lookup;

	/** @var IRequest */
	private $request;

	/** @var IClientService */
	private $clientService;

	/** @var IConfig */
	private $config;

	/**
	 * Master constructor.
	 *
	 * @param GlobalSiteSelector $gss
	 * @param ICrypto $crypto
	 * @param Lookup $lookup
	 * @param IRequest $request
	 * @param IClientService $clientService
	 * @param IConfig $config
	 */
	public function __construct(GlobalSiteSelector $gss,
								ICrypto $crypto,
								Lookup $lookup,
								IRequest $request,
								IClientService $clientService,
								IConfig $config
	) {
		$this->gss = $gss;
		$this->crypto = $crypto;
		$this->lookup = $lookup;
		$this->request = $request;
		$this->clientService = $clientService;
		$this->config = $config;
	}


	/**
	 * find users location and redirect them to the right server
	 *
	 * @param array $param
	 * @throws HintException
	 */
	public function handleLoginRequest($param) {

		// if there is a valid JWT it is a internal GSS request between master and slave
		// -> skip login
		$jwt = $this->request->getParam('jwt', '');
		if($this->isValidJwt($jwt)){
			return;
		}

		$options = [];
		$location = '';

		/** @var SAMLUserBackend $backend */
		$backend = isset($param['backend']) ? $param['backend'] : '';
		if (class_exists('\OCA\User_SAML\UserBackend') &&
			$backend instanceof \OCA\User_SAML\UserBackend
		) {
			$options['backend'] = 'saml';
			$options['userData'] = $backend->getUserData();
			$uid = $options['userData']['formatted']['uid'];
			$password = '';
			$location = $this->getLocationFromSAML($options['userData']['raw']);
			// we only send the formatted user data to the slave
			$options['userData'] = $options['userData']['formatted'];
		} else {
			$uid = $param['uid'];
			$password = isset($param['password']) ? $param['password'] : '';
		}

		// let the admin of the master node login, everyone else will redirected to a client
		$masterAdmins = $this->config->getSystemValue('gss.master.admin', []);
		if (is_array($masterAdmins) && in_array($uid, $masterAdmins, true)) {
			return;
		}

		if (empty($location)) {
			$location = $this->queryLookupServer($uid);
		}
		if (!empty($location)) {
			$this->redirectUser($uid, $password, $this->normalizeLocation($location), $options);
		} else {
			throw new HintException('Could not find location for user, ' . $uid);
		}
	}

	/**
	 * read user location from SAML parameters
	 *
	 * @param $options
	 * @return string
	 */
	protected function getLocationFromSAML($options) {
		$location = '';
		$parameter = $this->config->getSystemValue('gss.saml.slave.mapping', '');
		if (!empty($parameter) && isset($options[$parameter][0])) {
			$location = $options[$parameter][0];
		}

		return $location;
	}

	/**
	 * format URL
	 *
	 * @param string $url
	 * @return string
	 */
	protected function normalizeLocation($url) {
		if (substr($url, 0, 7) === 'http://' || substr($url, 0, 8) === 'https://') {
			return $url;
		}

		return $this->request->getServerProtocol() . '://' . $url;
	}

	/**
	 * search for the user and return the location of the user
	 *
	 * @param $uid
	 * @return string
	 */
	protected function queryLookupServer($uid) {
		return $this->lookup->search($uid);
	}

	/**
	 * redirect user to the right Nextcloud server
	 *
	 * @param string $uid
	 * @param string $password
	 * @param string $location
	 * @param array $options can contain additional parameters, e.g. from SAML
	 * @throws \Exception
	 */
	protected function redirectUser($uid, $password, $location, array $options = []) {

		$isClient = $this->request->isUserAgent(
			[
				IRequest::USER_AGENT_CLIENT_IOS,
				IRequest::USER_AGENT_CLIENT_ANDROID,
				IRequest::USER_AGENT_CLIENT_DESKTOP
			]
		);

		if($isClient) {
			$appToken = $this->getAppToken($location, $uid, $password);
			$redirectUrl = 'nc://login/server:' . $location . '&user:' . $uid . '&password:' . $appToken;
		} else {
			$jwt = $this->createJwt($uid, $password, $options);
			$redirectUrl = $location . '/index.php/apps/globalsiteselector/autologin?jwt=' . $jwt;
		}

		header('Location: ' . $redirectUrl);
		die();
	}

	/**
	 * generate JWT
	 *
	 * @param string $uid
	 * @param string $password
	 * @param array $options
	 * @return string
	 */
	protected function createJwt($uid, $password, $options) {
		$token = [
			'uid' => $uid,
			'password' => $this->crypto->encrypt($password, $this->gss->getJwtKey()),
			'options' => json_encode($options),
			'exp' => time() + 300, // expires after 5 minutes
		];

		$jwt = JWT::encode($token, $this->gss->getJwtKey());

		return $jwt;
	}

	/**
	 * get app token from the server the user is located
	 *
	 * @param string $location
	 * @param $uid
	 * @param $password
	 * @return string
	 * @throws \Exception
	 */
	protected function getAppToken($location, $uid, $password) {
		$client = $this->clientService->newClient();

		$baseUrl = $this->buildBasicAuthUrl($location, $uid, $password);
		$response = $client->get(
			$baseUrl . '/ocs/v2.php/apps/globalsiteselector/v1/createapptoken?format=json',
			[
				'headers' => [
					'OCS-APIRequest' => 'true'
				]
			]
		);

		$body = $response->getBody();

		$data = json_decode($body, true);
		$jsonErrorCode = json_last_error();
		if ($jsonErrorCode !== JSON_ERROR_NONE) {
			$info = 'getAppToken - Decoding the JSON failed ' .
				$jsonErrorCode . ' ' .
				json_last_error_msg();
			throw new \Exception($info);
		}
		if (!isset($data['ocs']['data']['token'])) {
			$info = 'getAppToken - data doesn\'t contain token: ' . json_encode($data);
			throw new \Exception($info);
		}

		return $data['ocs']['data']['token'];
	}

	/**
	 * add basic auth information to the URL
	 *
	 * @param string $url
	 * @param string $uid
	 * @param string $password
	 * @return string
	 */
	protected function buildBasicAuthUrl($url, $uid, $password) {
		if (strpos($url, 'http://') === 0) {
			$protocol = 'http://';
		} else if (strpos($url, 'https://') === 0) {
			$protocol = 'https://';
		} else {
			// no protocol given, switch to https as default
			$url = 'https://' . $url;
			$protocol = 'https://';
		}

		$basicAuth = $protocol . $uid . ':' . $password . '@';

		return str_replace($protocol, $basicAuth, $url);
	}

	private function isValidJwt($jwt) {
		try {
			$key = $this->gss->getJwtKey();
			JWT::decode($jwt, $key, ['HS256']);
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}

}
