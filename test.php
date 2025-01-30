<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mopeka Sensor Data</title>
</head>
<body>
    <h1>Sensor-Daten Analyse</h1>

    <!-- Formular f�r Benutzereingabe -->
    <form method="POST">
        <label for="testValue">Hex-Wert eingeben:</label>
        <input type="text" id="testValue" name="testValue" placeholder="z.B. 0002601b1f80f28906010c808104070c80800177be85b9" required>
        <button type="submit">Analyse starten</button>
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['testValue'])) {
        $testValue = $_POST['testValue'];

        // Validierung der Eingabe (nur Hex-Zeichen)
        if (!preg_match('/^[0-9a-fA-F]+$/', $testValue)) {
            echo "<p style='color:red;'>Ung�ltiger Hex-Wert! Bitte nur Hexadezimalzeichen (0-9, a-f).</p>";
            exit;
        }

        // Funktion zur Umwandlung eines Hex-Strings in ein Byte-Array
        function hexToBytes($hex) {
            $bytes = [];
            for ($i = 0; $i < strlen($hex); $i += 2) {
                $bytes[] = hexdec(substr($hex, $i, 2));
            }
            return $bytes;
        }

        // Funktion zur Berechnung des Batteriestands
        function parseBatteryLevel($raw_voltage) {
            $voltage = ($raw_voltage / 256.0) * 2.0 + 1.5;
            $percent = ($voltage - 2.2) / 0.65 * 100.0;
            return max(0, min(100, intval($percent)));
        }

        // Funktion zur Berechnung der Temperatur
        function parseTemperature($raw_temp) {
            if ($raw_temp == 0x0) {
                return -40;
            }
            return intval(($raw_temp - 25.0) * 1.776964);
        }

        // Funktion zur Berechnung der Schallgeschwindigkeit in LPG
        function getLpgSpeedOfSound($temperature, $mix) {
            return 1040.71 - 4.87 * $temperature - 137.5 * $mix 
                   - 0.0107 * $temperature * $temperature 
                   - 1.63 * $temperature * $mix;
        }

        // Hauptlogik
        $propane_butane_mix = 1.00;
        $byteArray = hexToBytes($testValue);

        $raw_voltage = $byteArray[2];
        $raw_temp = $byteArray[3] & 0x3F; // Letzten 6 Bits
        $battery = parseBatteryLevel($raw_voltage);
        $temperature = parseTemperature($raw_temp);

        echo "<h2>Ergebnisse</h2>";
        echo "<p>Battery Level: {$battery}%</p>";
        echo "<p>Temperature: {$temperature}�C</p>";

        // Messungen verarbeiten
        $measurements_time = [];
        $measurements_value = [];
        $index = 0;

        for ($i = 0; $i < 3; $i++) {
            $start = 4 + $i * 4; // Offset f�r Werte
            $measurements_time[] = ($byteArray[$start] & 0x1F) + 1; // time_0
            $measurements_value[] = ($byteArray[$start] >> 5) & 0x1F; // value_0
            $measurements_time[] = ($byteArray[$start + 1] & 0x1F) + 1; // time_1
            $measurements_value[] = ($byteArray[$start + 1] >> 5) & 0x1F; // value_1
            $measurements_time[] = ($byteArray[$start + 2] & 0x1F) + 1; // time_2
            $measurements_value[] = ($byteArray[$start + 2] >> 5) & 0x1F; // value_2
            $measurements_time[] = ($byteArray[$start + 3] & 0x1F) + 1; // time_3
            $measurements_value[] = ($byteArray[$start + 3] >> 5) & 0x1F; // value_3
        }

        // Beste Werte finden
        $measurement_time = 0;
        $best_value = 0;
        $best_time = 0;
        $usable_values = 0;

        foreach ($measurements_time as $i => $time) {
            $measurement_time += $time;
            if ($measurements_value[$i] != 0) {
                $usable_values++;
                if ($measurements_value[$i] > $best_value) {
                    $best_value = $measurements_value[$i];
                    $best_time = $measurement_time;
                }
                $measurement_time = 0;
            }
        }

        if ($usable_values < 1 || $best_value < 2 || $best_time < 2) {
            echo "<p>Poor read quality</p>";
        } else {
            $lpg_speed_of_sound = getLpgSpeedOfSound($temperature, $propane_butane_mix);
            $distance_value = intval($lpg_speed_of_sound * $best_time / 100.0);
            echo "<p>Distance: {$distance_value} mm</p>";

            $tank_level = (100.0 / (366.0 - 38.0)) * ($distance_value - 38.0);
            echo "<p>Tank level: " . round($tank_level, 2) . "%</p>";
        }
    }
    ?>
</body>
</html>
