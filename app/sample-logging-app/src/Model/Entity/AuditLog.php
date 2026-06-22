<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * AuditLog Entity
 *
 * @property int $id
 * @property string $trace_id
 * @property string $action
 * @property string $table_name
 * @property int|null $entity_id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $original_values
 * @property string|null $new_values
 * @property string|null $changed_fields
 * @property \Cake\I18n\FrozenTime $created
 *
 * @property \App\Model\Entity\User $user
 */
class AuditLog extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected $_accessible = [
        'trace_id' => true,
        'action' => true,
        'table_name' => true,
        'entity_id' => true,
        'user_id' => true,
        'ip_address' => true,
        'user_agent' => true,
        'original_values' => true,
        'new_values' => true,
        'changed_fields' => true,
        'created' => true,
        'user' => true,
    ];
}
