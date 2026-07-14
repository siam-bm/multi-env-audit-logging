<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\EncryptFieldsRegistry;
use Cake\Event\EventInterface;

/**
 * EncryptionFields Controller - manage which log fields are encrypted before
 * they leave the app (FieldCipher). Backed by config/encrypt_fields.json via
 * EncryptFieldsRegistry; changes apply from the next request, no deploy.
 */
class EncryptionFieldsController extends AppController
{
    /**
     * @param \Cake\Event\EventInterface $event Event.
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        return $this->requireLogin();
    }

    /**
     * List the encrypted fields + capability explainer.
     *
     * @return void
     */
    public function index()
    {
        $this->set('fields', EncryptFieldsRegistry::list());
    }

    /**
     * Add a field name to the encryption list.
     *
     * @return \Cake\Http\Response|null
     */
    public function add()
    {
        $this->request->allowMethod(['post']);
        $field = (string)$this->request->getData('field');

        if (EncryptFieldsRegistry::add($field)) {
            $this->Flash->success("'{$field}' added — new audit events will encrypt it from the next request.");
        } else {
            $this->Flash->error("Could not add '{$field}' — invalid name (use snake_case) or already in the list.");
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Remove a field name from the encryption list.
     *
     * @param string|null $field Field name.
     * @return \Cake\Http\Response|null
     */
    public function delete($field = null)
    {
        $this->request->allowMethod(['post']);

        if (EncryptFieldsRegistry::remove((string)$field)) {
            $this->Flash->success("'{$field}' removed — new events keep it in plaintext. Already-encrypted history stays encrypted (and still decrypts in the app).");
        } else {
            $this->Flash->error("'{$field}' was not in the list.");
        }

        return $this->redirect(['action' => 'index']);
    }
}
