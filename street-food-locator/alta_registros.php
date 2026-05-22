<?php
session_start();

// Limpiar preview si se solicita
if (isset($_GET['clear_preview'])) {
    unset($_SESSION['preview'], $_SESSION['total_preview']);
    exit; // Salir inmediatamente para respuesta limpia al fetch()
}

// Si el usuario entra sin confirmar importación y no está en carga masiva, limpiamos la vista previa
if (!isset($_POST['carga_masiva']) && !isset($_POST['confirmar_masiva'])) {
    unset($_SESSION['preview'], $_SESSION['total_preview']);
}

include("db.php");
ini_set('display_errors', 1);
error_reporting(E_ALL);

$mensaje = "";
$coincidencias = [];

// ─── Alta individual ───────────────────────────────────────────────────────────
if (isset($_POST['registro_manual'])) {
    $coincidencias = [];
    $curp            = strtoupper(trim($_POST['curp']));
    $nombre          = $_POST['nombre'];
    $primer_apellido = $_POST['primer_apellido'];
    $segundo_apellido= $_POST['segundo_apellido'];
    $fecha_nacimiento= $_POST['fecha_nacimiento'];
    $genero          = $_POST['genero'];
    $entidad         = $_POST['entidad'];

    try {
        $sql  = "INSERT INTO personas (curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, genero, entidad)
                 VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssss", $curp, $nombre, $primer_apellido, $segundo_apellido, $fecha_nacimiento, $genero, $entidad);
        $stmt->execute();
        $mensaje = "✅ Registro individual guardado correctamente.";
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
            $mensaje = "⚠️ El CURP $curp ya se encuentra dado de alta.";
        } else {
            $mensaje = "❌ Error al guardar registro individual: " . $e->getMessage();
        }
    }

    // Buscar coincidencia en reportes
    $sql_check = "SELECT * FROM reportes WHERE curp = ?";
    $stmt2 = $conn->prepare($sql_check);
    $stmt2->bind_param("s", $curp);
    $stmt2->execute();
    $result = $stmt2->get_result();
    while ($rep = $result->fetch_assoc()) {
        $coincidencias[] = "Coincidencia encontrada en reportes:<br>
            CURP: {$rep['curp']}<br>
            Nombre: {$rep['nombre']} {$rep['primer_apellido']} {$rep['segundo_apellido']}<br>
            Fecha desaparición: {$rep['fecha_desaparicion']}<br>
            Lugar nacimiento: {$rep['lugar_nacimiento']}<br>
            Teléfono: {$rep['telefono']}";
    }
    $stmt2->close();
}

// ─── Alta masiva – vista previa ────────────────────────────────────────────────
if (isset($_POST['carga_masiva'])) {
    $coincidencias = [];

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file    = fopen($_FILES['csv_file']['tmp_name'], "r");
        $preview = [];
        $total   = 0;

        while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
            if (count($data) < 7) continue;

            $curp             = strtoupper(trim($data[0]));
            $nombre           = trim($data[1]);
            $primer_apellido  = trim($data[2]);
            $segundo_apellido = trim($data[3]);
            $fecha_nacimiento = trim(str_replace('/', '-', $data[4]));
            $genero           = strtoupper(trim($data[5]));
            $entidad          = trim($data[6]);

            if (empty($curp)) continue;

            $nombre_completo = trim("$nombre $primer_apellido $segundo_apellido");

            // Verificar duplicado
            $sql_check = "SELECT 1 FROM personas WHERE curp = ?";
            $stmt = $conn->prepare($sql_check);
            $stmt->bind_param("s", $curp);
            $stmt->execute();
            $stmt->store_result();
            $duplicado = $stmt->num_rows > 0;
            $stmt->close();

            $preview[] = [
                'curp'             => $curp,
                'nombre'           => $nombre,
                'primer_apellido'  => $primer_apellido,
                'segundo_apellido' => $segundo_apellido,
                'fecha_nacimiento' => $fecha_nacimiento,
                'genero'           => $genero,
                'entidad'          => $entidad,
                'nombre_completo'  => $nombre_completo,
                'duplicado'        => $duplicado,
            ];
            $total++;
        }
        fclose($file);

        $_SESSION['preview']       = $preview;
        $_SESSION['total_preview'] = $total;
        $mensaje = "📋 Se han leído <strong>$total</strong> registros del archivo. Revisa la tabla antes de importar.";
    } else {
        $codigo  = $_FILES['csv_file']['error'] ?? 'N/A';
        $mensaje = "❌ Error al subir archivo CSV. Código: $codigo";
    }
}

// ─── Confirmación de carga masiva ─────────────────────────────────────────────
if (isset($_POST['confirmar_masiva']) && isset($_SESSION['preview'])) {
    $importados = 0;
    $errores    = 0;

    foreach ($_SESSION['preview'] as $index => $row) {
        if (isset($_POST['importar'][$index]) && !$row['duplicado']) {
            try {
                $sql  = "INSERT INTO personas (curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, genero, entidad)
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssss",
                    $row['curp'], $row['nombre'], $row['primer_apellido'],
                    $row['segundo_apellido'], $row['fecha_nacimiento'],
                    $row['genero'], $row['entidad']
                );
                if ($stmt->execute()) {
                    $importados++;
                } else {
                    $errores++;
                }
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $errores++;
            }
        }
    }

    $mensaje = "✅ Se importaron <strong>$importados</strong> registros correctamente. Errores: <strong>$errores</strong>.";
    unset($_SESSION['preview'], $_SESSION['total_preview']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<!-- CRÍTICO para iframe: permite que el formulario funcione dentro del iframe -->
<base target="_self">
<title>Alta Registros</title>
<link rel="stylesheet" href="style.css">
<style>
  /* Estilos de respaldo si style.css no carga */
  body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
  .header { background: #1a237e; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; }
  .header h1 { margin: 0; font-size: 1.1rem; }
  .content { padding: 20px; }
  .mensaje { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 6px; padding: 12px; margin: 10px 0; }
  .styled-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .styled-table th, .styled-table td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
  .styled-table thead { background: #1a237e; color: #fff; }
  button { background: #1a237e; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; margin: 4px 2px; }
  button:hover { background: #283593; }
  label { display: block; margin-top: 8px; font-weight: bold; }
  input[type=text], input[type=date], input[type=file], select { width: 100%; padding: 6px; margin-top: 2px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
  .back-button { background: #555; }
</style>
<script>
  // ── Definir limpiarPreview ANTES de usarla en onclick ──────────────────────
  function limpiarPreview() {
    // Petición al mismo origen para limpiar la sesión
    fetch(window.location.pathname + '?clear_preview=1', { method: 'GET' })
      .catch(function() { /* silenciar errores de red */ });
  }

  function mostrarForm(tipo) {
    document.getElementById('form_manual').style.display  = (tipo === 'manual') ? 'block' : 'none';
    document.getElementById('form_masivo').style.display  = (tipo === 'masivo') ? 'block' : 'none';
    var msgEl = document.getElementById('mensajes');
    var coinEl = document.getElementById('coincidencias');
    if (msgEl)  msgEl.innerHTML  = '';
    if (coinEl) coinEl.innerHTML = '';
  }

  // Seleccionar / deseleccionar todos los checkboxes habilitados
  function toggleTodos(checked) {
    var checkboxes = document.querySelectorAll('input[name^="importar"]');
    checkboxes.forEach(function(cb) { if (!cb.disabled) cb.checked = checked; });
  }
</script>
</head>
<body>

<!-- Encabezado institucional -->
<div class="header">
  <div class="logo" style="display:flex; align-items:center; gap:10px;">
    <img src="img/suma-tools.png" alt="Logo SUMA Tools" style="height:40px;">
    <h1>PLATAFORMA ÚNICA DE IDENTIDAD</h1>
  </div>
  <div class="user">Usuario | En línea</div>
</div>

<!-- Panel principal -->
<div class="content">
  <h2>Actualizar Registros</h2>

  <!-- Botones de selección de modo -->
  <button onclick="mostrarForm('manual'); limpiarPreview();">Registro Manual</button>
  <button onclick="mostrarForm('masivo'); limpiarPreview();">Carga Masiva</button>

  <!-- ── Formulario individual ──────────────────────────────────────────────── -->
  <!--
    action="" apunta al mismo archivo; target="_self" asegura que la respuesta
    se muestre dentro del iframe y no rompa el frame padre.
  -->
  <form id="form_manual" method="POST" action="" target="_self" style="display:none;">
    <input type="hidden" name="registro_manual" value="1">
    <label>CURP</label>
    <input type="text" name="curp" required>
    <label>Nombre</label>
    <input type="text" name="nombre" required>
    <label>Primer Apellido</label>
    <input type="text" name="primer_apellido" required>
    <label>Segundo Apellido</label>
    <input type="text" name="segundo_apellido">
    <label>Fecha Nacimiento</label>
    <input type="date" name="fecha_nacimiento" required>
    <label>Género</label>
    <select name="genero" required>
      <option value="H">Hombre</option>
      <option value="M">Mujer</option>
    </select>
    <label>Entidad</label>
    <input type="text" name="entidad" required>
    <br><br>
    <button type="submit">Guardar Registro</button>
  </form>

  <!-- ── Formulario carga masiva ────────────────────────────────────────────── -->
  <!--
    enctype="multipart/form-data" es OBLIGATORIO para subir archivos.
    target="_self" evita que el iframe intente abrir una nueva ventana.
  -->
  <form id="form_masivo" method="POST" action="" enctype="multipart/form-data" target="_self" style="display:none;">
    <input type="hidden" name="carga_masiva" value="1">
    <label>Archivo CSV</label>
    <input type="file" name="csv_file" accept=".csv" required>
    <br><br>
    <button type="submit">Leer Archivo</button>
  </form>

  <!-- ── Mensajes ───────────────────────────────────────────────────────────── -->
  <div id="mensajes">
    <?php if (!empty($mensaje)): ?>
      <div class="mensaje"><?php echo $mensaje; ?></div>
    <?php endif; ?>
  </div>

  <!-- ── Vista previa de carga masiva ──────────────────────────────────────── -->
  <?php if (!empty($_SESSION['preview'])): ?>
  <div class="mensaje">
    <strong>Vista previa de registros a importar (<?php echo $_SESSION['total_preview']; ?>):</strong>

    <!--
      CRÍTICO: este formulario NO tiene enctype multipart porque no sube archivos.
      target="_self" para que la respuesta quede dentro del iframe.
    -->
    <form method="POST" action="" target="_self">
      <input type="hidden" name="confirmar_masiva" value="1">

      <div style="margin: 8px 0;">
        <button type="button" onclick="toggleTodos(true)"  style="background:#388e3c;">✔ Seleccionar todos</button>
        <button type="button" onclick="toggleTodos(false)" style="background:#c62828;">✘ Deseleccionar todos</button>
      </div>

      <table class="styled-table">
        <thead>
          <tr>
            <th>Importar</th>
            <th>CURP</th>
            <th>Nombre Completo</th>
            <th>Fecha Nac.</th>
            <th>Género</th>
            <th>Entidad</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($_SESSION['preview'] as $index => $row): ?>
          <tr style="<?php echo $row['duplicado'] ? 'background:#fff3e0;' : ''; ?>">
            <td style="text-align:center;">
              <?php if ($row['duplicado']): ?>
                <input type="checkbox" disabled title="Registro duplicado">
              <?php else: ?>
                <input type="checkbox"
                       name="importar[<?php echo $index; ?>]"
                       value="1"
                       checked>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($row['curp']); ?></td>
            <td><?php echo htmlspecialchars($row['nombre_completo']); ?></td>
            <td><?php echo htmlspecialchars($row['fecha_nacimiento']); ?></td>
            <td><?php echo htmlspecialchars($row['genero']); ?></td>
            <td><?php echo htmlspecialchars($row['entidad']); ?></td>
            <td>
              <?php if ($row['duplicado']): ?>
                <span style="color:#c62828; font-weight:bold;">⚠ DUPLICADO</span>
              <?php else: ?>
                <span style="color:#388e3c;">✔ Disponible</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <br>
      <button type="submit" style="background:#1b5e20; font-size:1rem; padding:10px 24px;">
        ✅ Confirmar Importación
      </button>
    </form>
  </div>
  <?php endif; ?>

  <!-- ── Coincidencias ──────────────────────────────────────────────────────── -->
  <div id="coincidencias">
    <?php if (!empty($coincidencias)): ?>
    <div class="mensaje">
      <strong>Coincidencias encontradas:</strong>
      <ul>
        <?php foreach ($coincidencias as $c): ?>
          <li><?php echo $c; ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Botón regresar ─────────────────────────────────────────────────────── -->
  <form action="menu.php" method="GET" target="_top" style="margin-top:20px; text-align:center;">
    <button class="back-button" type="submit">⬅️ Regresar al Menú</button>
  </form>

</div><!-- /.content -->
</body>
</html>
