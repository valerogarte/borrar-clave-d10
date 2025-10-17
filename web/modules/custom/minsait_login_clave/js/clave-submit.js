/**
 * JavaScript para manejar el envío del formulario de Cl@ve.
 */

/**
 * Función para enviar el formulario de Cl@ve con los parámetros necesarios.
 */
function submitClaveForm(button, event) {
  // Obtener el evento de manera compatible con todos los navegadores
  const evt = event || window.event;
  
  // Prevenir el comportamiento por defecto
  if (evt && evt.preventDefault) {
    evt.preventDefault();
  }
  
  // Obtener los datos del botón
  const action = button.getAttribute('data-action');
  const loa = button.getAttribute('data-loa');
  const sptype = button.getAttribute('data-sptype');
  const forceauthn = button.getAttribute('data-forceauthn');
  
  // Crear un formulario dinámico
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = action;
  
  // Crear campos hidden con los parámetros necesarios
  const fields = [
    { name: 'loa', value: loa },
    { name: 'SPType', value: sptype },
    { name: 'forceauthn', value: forceauthn }
  ];
  
  fields.forEach(function(field) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = field.name;
    input.value = field.value;
    form.appendChild(input);
  });
  
  // Añadir el formulario al body y enviarlo
  document.body.appendChild(form);
  form.submit();
  
  return false;
}

// Exponer la función globalmente para que sea accesible desde el onclick
window.submitClaveForm = submitClaveForm;