<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpFoundation\Response;

class kernel_request_subscriber implements EventSubscriberInterface
{
	/**
	* Auth object
	*
	* @var \phpbb\auth\auth
	*/
	protected $auth;

	/**
	* User object
	*
	* @var \phpbb\user
	*/
	protected $user;

	/**
	* Construct method
	*
	* @param \phpbb\auth\auth	$auth	Auth object
	* @param \phpbb\user		$user	User object
	*/
	public function __construct(\phpbb\auth\auth $auth, \phpbb\user $user)
	{
		$this->auth = $auth;
		$this->user = $user;
	}

	/**
	* This listener is run when the KernelEvents::REQUEST event is triggered
	*
	* @param GetResponseEvent $event
	* @return null
	*/
	public function on_push_request(GetResponseEvent $event)
	{
		$request = $event->getRequest();
		if (!$request->attributes->get('skip_session', false))
		{
			// Start session management
			$this->user->session_begin();
			$this->auth->acl($this->user->data);
			$this->user->setup('app');
		}
	}

	static public function getSubscribedEvents()
	{
		return array(
			KernelEvents::REQUEST		=> 'on_push_request',
		);
	}
}
