<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Form\Type\Admin;

use Eccube\Form\DataTransformer;
use Eccube\Entity\Master\OrderItemType;
use Eccube\Entity\Master\TaxType;
use Eccube\Entity\Master\TaxDisplayType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ShipmentItemType extends AbstractType
{
    protected $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $config = $this->app['config'];

        $builder
            ->add('new', HiddenType::class, array(
                'required' => false,
                'mapped' => false,
                'data' => 1
            ))
            ->add('id', HiddenType::class, array(
                'required' => false,
                'mapped' => false
            ))
            ->add('price', MoneyType::class, array(
                'currency' => 'JPY',
                'scale' => 0,
                'grouping' => true,
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'max' => $config['int_len'],
                    )),
                ),
            ))
            ->add('quantity', TextType::class, array(
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'max' => $config['int_len'],
                    )),
                ),
            ))
            ->add('tax_rate', TextType::class, array(
                'constraints' => array(
                    new Assert\NotBlank(),
                    new Assert\Length(array(
                        'max' => $config['int_len'],
                    )),
                    new Assert\Regex(array(
                        'pattern' => "/^\d+(\.\d+)?$/u",
                        'message' => 'form.type.float.invalid'
                    )),
                )
            ))
            ->add('product_name', HiddenType::class)
            ->add('product_code', HiddenType::class)
            ->add('class_name1', HiddenType::class)
            ->add('class_name2', HiddenType::class)
            ->add('class_category_name1', HiddenType::class)
            ->add('class_category_name2', HiddenType::class)
            ->add('tax_rule', HiddenType::class)
            // ->add('order_id', HiddenType::class)
        ;

        $builder
            ->add($builder->create('order_item_type', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Master\OrderItemType'
                )))
            ->add($builder->create('tax_type', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Master\TaxType'
                )))
            ->add($builder->create('tax_display_type', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Master\TaxDisplayType'
                )))
            ->add($builder->create('Product', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Product'
                )))
            ->add($builder->create('ProductClass', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\ProductClass'
                )))
            ->add($builder->create('Order', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Order'
                )))
            ->add($builder->create('Shipping', HiddenType::class)
                ->addModelTransformer(new DataTransformer\EntityToIdTransformer(
                    $this->app['orm.em'],
                    '\Eccube\Entity\Shipping'
                )));

        $app = $this->app;
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($app) {
            // モーダルからのPOST時に、金額等をセットする.
            if ('modal' === $app['request_stack']->getCurrentRequest()->get('modal')) {
                $data = $event->getData();
                // 新規明細行の場合にセット.
                if (isset($data['new'])) {
                    /** @var \Eccube\Entity\ShipmentItem $ShipmentItem */
                    $ShipmentItem = $app['eccube.repository.shipment_item']
                        ->find($data['id']);
                    $data = array_merge($data, $ShipmentItem->toArray(['Order', 'Product', 'ProductClass', 'Shipping', 'TaxType', 'TaxDisplayType', 'OrderItemType']));

                    if (is_object($ShipmentItem->getOrder())) {
                        $data['Order'] = $ShipmentItem->getOrder()->getId();
                    }
                    if (is_object($ShipmentItem->getProduct())) {
                        $data['Product'] = $ShipmentItem->getProduct()->getId();
                    }
                    if (is_object($ShipmentItem->getProduct())) {
                        $data['ProductClass'] = $ShipmentItem->getProductClass()->getId();
                    }
                    if (is_object($ShipmentItem->getTaxType())) {
                        $data['tax_type'] = $ShipmentItem->getTaxType()->getId();
                    }
                    if (is_object($ShipmentItem->getTaxDisplayType())) {
                        $data['tax_display_type'] = $ShipmentItem->getTaxDisplayType()->getId();
                    }
                    if (is_object($ShipmentItem->getOrderItemType())) {
                        $data['order_item_type'] = $ShipmentItem->getOrderItemType()->getId();
                    }
                    $event->setData($data);
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Eccube\Entity\ShipmentItem',
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'shipment_item';
    }
}
