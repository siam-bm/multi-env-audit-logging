<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product $product
 */
?>
<div class="row">
    <aside class="column">
        <div class="side-nav">
            <h4 class="heading"><?= __('Actions') ?></h4>
            <?= $this->Html->link(__('List Products'), ['action' => 'index'], ['class' => 'side-nav-item']) ?>
        </div>
    </aside>
    <div class="column-responsive column-80">
        <div class="products form content">
            <?= $this->Form->create($product) ?>
            <fieldset>
                <legend><?= __('Add Product') ?></legend>
                <?php
                    echo $this->Form->control('name', [
                        'label' => 'Product Name',
                        'required' => true,
                        'placeholder' => 'Enter product name'
                    ]);
                    echo $this->Form->control('description', [
                        'label' => 'Description',
                        'type' => 'textarea',
                        'rows' => 4,
                        'placeholder' => 'Enter product description'
                    ]);
                    echo $this->Form->control('price', [
                        'label' => 'Price ($)',
                        'type' => 'number',
                        'step' => '0.01',
                        'min' => '0',
                        'required' => true,
                        'placeholder' => '0.00'
                    ]);
                    echo $this->Form->control('quantity', [
                        'label' => 'Quantity',
                        'type' => 'number',
                        'min' => '0',
                        'default' => 0,
                        'required' => true
                    ]);
                    echo $this->Form->control('status', [
                        'label' => 'Status',
                        'options' => [
                            'active' => 'Active',
                            'inactive' => 'Inactive',
                            'out_of_stock' => 'Out of Stock'
                        ],
                        'default' => 'active'
                    ]);
                ?>
            </fieldset>
            <?= $this->Form->button(__('Submit'), ['class' => 'button primary']) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>