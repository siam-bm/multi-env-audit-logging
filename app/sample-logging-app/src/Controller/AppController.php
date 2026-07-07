<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;

/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/4/en/controllers.html#the-app-controller
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('RequestHandler');
        $this->loadComponent('Flash');
    }

    /**
     * Runs before every action. Resolves the current logged-in user from the
     * session, shares it with the views (for the nav bar), and publishes it to
     * Configure so the AuditLogBehavior can attribute changes to the actor.
     *
     * @param \Cake\Event\EventInterface $event Controller event.
     * @return void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $user = $this->getRequest()->getSession()->read('Auth.User');

        // Publish the actor so models (AuditLogBehavior) can record who acted.
        Configure::write('Audit.actor', $user);

        // Publish the session id — the "maintained id" that ties together every
        // action in one login session (spans many requests). AuditLogBehavior
        // stamps it on each log line so a whole session can be reconstructed.
        Configure::write('Audit.session_id', $this->getRequest()->getSession()->id());

        // Make it available to every template (nav bar etc.).
        $this->set('currentUser', $user);
    }

    /**
     * Redirect to the login page when no user is authenticated. Controllers
     * that manage data (Products, Users) call this from their beforeFilter.
     *
     * @return \Cake\Http\Response|null Redirect response when not logged in.
     */
    protected function requireLogin()
    {
        if (!$this->getRequest()->getSession()->check('Auth.User')) {
            $this->Flash->error('Please log in to continue.');

            return $this->redirect(['controller' => 'Users', 'action' => 'login']);
        }

        return null;
    }
}
