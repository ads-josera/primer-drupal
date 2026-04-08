<?php

use Drupal\webform\Entity\Webform;

$webform_id = 'contacto_prueba';
$webform = Webform::load($webform_id);

if (!$webform) {
  $webform = Webform::create([
    'id' => $webform_id,
    'title' => 'Formulario de prueba',
    'description' => 'Formulario Webform de prueba creado desde terminal.',
    'status' => Webform::STATUS_OPEN,
  ]);

  $webform->setElements([
    'nombre' => [
      '#type' => 'textfield',
      '#title' => 'Nombre',
      '#required' => TRUE,
    ],
    'correo' => [
      '#type' => 'email',
      '#title' => 'Correo electronico',
      '#required' => TRUE,
    ],
    'mensaje' => [
      '#type' => 'textarea',
      '#title' => 'Mensaje',
      '#required' => TRUE,
    ],
  ]);

  $webform->save();
  print "created\n";
}
else {
  print "exists\n";
}
