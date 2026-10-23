(function () {
  "use strict";

  var ENDPOINT = "api/reportar.php";
  var TOKEN_MS = 60 * 1000;

  var form = document.getElementById("form-reporte");
  if (!form) return;

  var btn = document.getElementById("btn-enviar");
  var cajaMensaje = document.getElementById("mensaje-estado");
  var inputToken = document.getElementById("csrf_token");
  var barraProgreso = document.getElementById("progreso-barra");
  var rellenoProgreso = document.getElementById("progreso-relleno");
  var valorProgreso = document.getElementById("progreso-valor");
  var textoProgreso = document.getElementById("progreso-texto");
  var descripcion = document.getElementById("descripcion");
  var contadorDescripcion = document.getElementById("descripcion-contador");
  var movimientoReducido = window.matchMedia
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : null;

  var enviando = false;
  var tokenExpiradoEn = 0;

  var CAMPOS_OBLIGATORIOS = [
    "tipo",
    "fecha",
    "hora",
    "estado",
    "ubicacion",
    "ciudad",
    "descripcion"
  ];

  var CAMPOS = {
    tipo: "Selecciona el tipo de incidente.",
    fecha: "Indica la fecha del incidente.",
    hora: "Indica la hora aproximada del incidente.",
    ubicacion: "Describe la ubicación del incidente.",
    ciudad: "Indica la ciudad o municipio.",
    descripcion: "Describe brevemente los hechos.",
    estado: "Indica el estado del incidente.",
    nombre: "",
    correo: "Revisa el correo electrónico ingresado.",
    telefono: "Revisa el teléfono ingresado."
  };

  function hoyISO() {
    var d = new Date();
    var mes = String(d.getMonth() + 1).padStart(2, "0");
    var dia = String(d.getDate()).padStart(2, "0");
    return d.getFullYear() + "-" + mes + "-" + dia;
  }

  function horaISO() {
    var d = new Date();
    return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
  }

  function predefinirMomento() {
    var fecha = document.getElementById("fecha");
    var hora = document.getElementById("hora");
    if (fecha && !fecha.value) fecha.value = hoyISO();
    if (hora && !hora.value) hora.value = horaISO();
  }

  function actualizarProgreso() {
    var completos = 0;
    CAMPOS_OBLIGATORIOS.forEach(function (nombre) {
      var campo = document.getElementById(nombre);
      if (campo && campo.value.trim() !== "") completos++;
    });

    var total = CAMPOS_OBLIGATORIOS.length;
    var porcentaje = Math.round((completos / total) * 100);

    if (rellenoProgreso) rellenoProgreso.style.width = porcentaje + "%";
    if (barraProgreso) barraProgreso.setAttribute("aria-valuenow", String(porcentaje));
    if (valorProgreso) valorProgreso.textContent = porcentaje + "%";
    if (textoProgreso) {
      textoProgreso.textContent = completos === total
        ? "Campos obligatorios completos. Revisa la información y envía el reporte."
        : completos + " de " + total + " campos obligatorios completados.";
    }
  }

  function actualizarContador() {
    if (descripcion && contadorDescripcion) {
      contadorDescripcion.textContent = descripcion.value.length + " / 2000";
    }
  }

  function reiniciarFormulario() {
    form.reset();
    predefinirMomento();
    actualizarProgreso();
    actualizarContador();
  }

  function obtenerToken(forzar) {
    if (!forzar && inputToken.value && Date.now() < tokenExpiradoEn) {
      return Promise.resolve(inputToken.value);
    }
    return fetch(ENDPOINT, {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "same-origin"
    })
      .then(function (r) { return r.json(); })
      .then(function (datos) {
        if (!datos || !datos.ok || !datos.token) {
          throw new Error("token-no-disponible");
        }
        inputToken.value = datos.token;
        tokenExpiradoEn = Date.now() + TOKEN_MS * 15;
        return datos.token;
      });
  }

  function limpiarErrores() {
    form.querySelectorAll(".campo.con-error").forEach(function (c) {
      c.classList.remove("con-error");
    });
    form.querySelectorAll(".error-campo").forEach(function (e) {
      e.textContent = "";
    });
  }

  function marcarError(campo, texto) {
    var input = document.getElementById(campo);
    if (!input) return;
    var contenedor = input.closest(".campo");
    var caja = document.getElementById(campo + "-error");
    if (contenedor) contenedor.classList.add("con-error");
    if (caja) caja.textContent = texto;
  }

  function mostrarMensaje(tipo, titulo, detalle) {
    cajaMensaje.hidden = false;
    cajaMensaje.className = "mensaje " + tipo;
    cajaMensaje.innerHTML = "";
    var strong = document.createElement("strong");
    strong.textContent = titulo;
    cajaMensaje.appendChild(strong);
    if (detalle) {
      var span = document.createElement("span");
      span.textContent = detalle;
      cajaMensaje.appendChild(span);
    }
    var comportamiento = movimientoReducido && movimientoReducido.matches ? "auto" : "smooth";
    cajaMensaje.scrollIntoView({ behavior: comportamiento, block: "center" });
  }

  function ocultarMensaje() {
    cajaMensaje.hidden = true;
    cajaMensaje.textContent = "";
  }

  function validar() {
    limpiarErrores();
    var errores = 0;
    var datos = new FormData(form);

    function falta(nombre) {
      var valor = (datos.get(nombre) || "").toString().trim();
      return valor.length === 0;
    }

    Object.keys(CAMPOS).forEach(function (nombre) {
      if (nombre === "nombre" || nombre === "correo" || nombre === "telefono") return;
      if (falta(nombre)) {
        marcarError(nombre, CAMPOS[nombre]);
        errores++;
      }
    });

    if (!falta("ubicacion") && (datos.get("ubicacion") || "").trim().length < 5) {
      marcarError("ubicacion", "La ubicación debe tener al menos 5 caracteres.");
      errores++;
    }
    if (!falta("descripcion") && (datos.get("descripcion") || "").trim().length < 10) {
      marcarError("descripcion", "La descripción debe tener al menos 10 caracteres.");
      errores++;
    }

    var fecha = (datos.get("fecha") || "").toString();
    if (fecha && !/^\d{4}-\d{2}-\d{2}$/.test(fecha)) {
      marcarError("fecha", "Ingresa una fecha válida.");
      errores++;
    } else if (fecha && fecha > hoyISO()) {
      marcarError("fecha", "La fecha del incidente no puede ser futura.");
      errores++;
    }

    var correo = (datos.get("correo") || "").toString().trim();
    if (correo && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(correo)) {
      marcarError("correo", CAMPOS.correo);
      errores++;
    }

    var telefono = (datos.get("telefono") || "").toString().trim();
    if (telefono && !/^\+?[0-9\s\-()]{7,20}$/.test(telefono)) {
      marcarError("telefono", CAMPOS.telefono);
      errores++;
    }

    return errores;
  }

  function enviarDatos(datos) {
    return fetch(ENDPOINT, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Accept": "application/json" },
      body: datos
    }).then(function (respuesta) {
      return respuesta.json().catch(function () { return null; }).then(function (cuerpo) {
        return { status: respuesta.status, cuerpo: cuerpo };
      });
    });
  }

  function procesarRespuesta(status, cuerpo) {
    if (!cuerpo) {
      mostrarMensaje("error", "No fue posible completar el envío.",
        "Ocurrió un problema al procesar la solicitud. Verifica tu conexión e inténtalo de nuevo.");
      return;
    }

    if (cuerpo.errores) {
      limpiarErrores();
      var total = 0;
      Object.keys(cuerpo.errores).forEach(function (campo) {
        marcarError(campo, cuerpo.errores[campo]);
        total++;
      });
      var primerError = form.querySelector(".campo.con-error input, .campo.con-error select, .campo.con-error textarea");
      if (primerError) primerError.focus();
      mostrarMensaje("error", "Revisa los campos marcados.",
        total === 1 ? "Hay 1 campo con información no válida." : "Hay " + total + " campos con información no válida.");
      return;
    }

    if (cuerpo.ok && cuerpo.status === "enviado") {
      mostrarMensaje("exito", "Reporte recibido y registrado.",
        "Folio " + cuerpo.folio + ". La notificación fue aceptada por el servidor de correo y quedó en cola para entrega a la dependencia encargada de recibir el reporte.");
      reiniciarFormulario();
      obtenerToken(true).catch(function () {});
      return;
    }

    if (cuerpo.ok && cuerpo.status === "registrado_sin_correo") {
      mostrarMensaje("aviso", "Reporte registrado.",
        "Folio " + cuerpo.folio + ". El reporte quedó guardado, pero la notificación por correo no pudo enviarse porque el canal de correo aún no está disponible.");
      reiniciarFormulario();
      obtenerToken(true).catch(function () {});
      return;
    }

    if (cuerpo.ok && cuerpo.status === "correo_fallido") {
      mostrarMensaje("aviso", "Reporte registrado, pero la notificación falló.",
        "Folio " + cuerpo.folio + ". El reporte quedó guardado, pero no fue posible enviar el correo de notificación. No se envió ningún correo. Intenta de nuevo más tarde.");
      reiniciarFormulario();
      obtenerToken(true).catch(function () {});
      return;
    }

    if (cuerpo.ok && cuerpo.status === "duplicado") {
      mostrarMensaje("exito", "Este reporte ya fue registrado.",
        "Folio " + cuerpo.folio + ". No es necesario enviarlo de nuevo.");
      reiniciarFormulario();
      obtenerToken(true).catch(function () {});
      return;
    }

    if (status === 429) {
      mostrarMensaje("aviso", "Demasiados intentos.",
        cuerpo.mensaje || "Has enviado varios reportes en poco tiempo. Espera unos minutos y vuelve a intentarlo.");
      return;
    }

    if (status === 403) {
      mostrarMensaje("error", "La sesión expiró.",
        "Recargamos la protección del formulario. Intenta enviarlo una vez más.");
      return;
    }

    mostrarMensaje("error", "No fue posible enviar el reporte.",
      cuerpo.mensaje || "Revisa los datos e inténtalo de nuevo. Tus datos se conservaron en el formulario.");
  }

  form.addEventListener("submit", function (evento) {
    evento.preventDefault();
    if (enviando) return;
    var errores = validar();
    if (errores > 0) {
      mostrarMensaje("error", "Revisa la información ingresada.",
        "Corrige los campos marcados antes de enviar el reporte.");
      var primerError = form.querySelector(".campo.con-error input, .campo.con-error select, .campo.con-error textarea");
      if (primerError) primerError.focus();
      return;
    }

    enviando = true;
    btn.disabled = true;
    btn.classList.add("enviando");
    btn.setAttribute("aria-busy", "true");
    ocultarMensaje();

    obtenerToken(false)
      .then(function (token) {
        var datos = new FormData(form);
        datos.set("csrf_token", token);
        return enviarDatos(datos);
      })
      .then(function (resultado) {
        if (resultado.status === 403 && resultado.cuerpo && resultado.cuerpo.csrf) {
          return obtenerToken(true).then(function (token) {
            var datos = new FormData(form);
            datos.set("csrf_token", token);
            return enviarDatos(datos);
          });
        }
        return resultado;
      })
      .then(function (resultado) {
        procesarRespuesta(resultado.status, resultado.cuerpo);
      })
      .catch(function () {
        mostrarMensaje("error", "No fue posible enviar el reporte.",
          "No se pudo conectar con el servidor. Verifica tu conexión e inténtalo de nuevo. Tus datos se conservaron en el formulario.");
      })
      .then(function () {
        enviando = false;
        btn.disabled = false;
        btn.classList.remove("enviando");
        btn.removeAttribute("aria-busy");
      });
  });

  form.addEventListener("input", function (evento) {
    var campo = evento.target.closest(".campo");
    if (campo && campo.classList.contains("con-error")) {
      campo.classList.remove("con-error");
      var caja = campo.querySelector(".error-campo");
      if (caja) caja.textContent = "";
    }
    actualizarProgreso();
    actualizarContador();
  });

  form.addEventListener("change", function () {
    actualizarProgreso();
  });

  predefinirMomento();
  actualizarProgreso();
  actualizarContador();
  obtenerToken(false).catch(function () {});
})();
