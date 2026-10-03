#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <ESP32Servo.h>

// The Arduino IDE inserts its generated prototypes here, above the struct
// definition further down, so the type has to be known at this point.
struct SensorData;


// ============================================================
// PEDALYA
// IoT-Based Bicycle Rental Prototype
//
// Hardware:
// ESP32 DevKit V1
// MPU6050 GY-521
// 16x2 I2C LCD
// Active buzzer
// MG996R servo
// Quectel EC20 cellular module
// ============================================================


// ============================================================
// PIN CONFIGURATION
// ============================================================

// ---------- I2C ----------
#define SDA_PIN 21
#define SCL_PIN 22

// ---------- Buzzer ----------
#define BUZZER_PIN 19

// ---------- Servo ----------
#define SERVO_PIN 18

// ---------- EC20 UART ----------
#define EC20_RX 16     // ESP32 RX2
#define EC20_TX 17     // ESP32 TX2


// ============================================================
// OBJECTS
// ============================================================

LiquidCrystal_I2C lcd(0x27, 16, 2);

Servo brakeServo;

HardwareSerial EC20(2);


// ============================================================
// MPU6050
// ============================================================

#define MPU6050_ADDR 0x68

#define MPU6050_PWR_MGMT_1   0x6B
#define MPU6050_ACCEL_XOUT_H 0x3B
#define MPU6050_GYRO_XOUT_H  0x43


// ============================================================
// SERVO POSITIONS
// ============================================================

// Adjust these after physically installing the servo.
//
// IMPORTANT:
// These values are ONLY prototype positions.
// Do not assume they are safe for the real bicycle.
// Test the mechanical actuator with the wheel lifted first.

#define SERVO_RELEASED 20
#define SERVO_LIMITED  70

int currentServoPosition = SERVO_RELEASED;


// ============================================================
// RENTAL SETTINGS
// ============================================================

// Prototype rental duration.
// Change this later according to your actual system.

const unsigned long RENTAL_DURATION =
  30UL * 60UL * 1000UL;   // 30 minutes

const unsigned long GRACE_PERIOD =
  5UL * 60UL * 1000UL;    // 5 minutes


unsigned long rentalStartTime = 0;

bool rentalActive = false;
bool graceActive = false;
bool rentalExpired = false;


// ============================================================
// ACCIDENT DETECTION SETTINGS
// ============================================================

// These are INITIAL prototype thresholds.
// They must be calibrated through your actual testing.

const float IMPACT_THRESHOLD = 2.5;   // g

const float GYRO_THRESHOLD = 250.0;   // degrees/sec

const float TILT_THRESHOLD = 55.0;    // degrees


bool accidentDetected = false;
bool tiltDetected = false;


// ============================================================
// BUZZER
// ============================================================

bool buzzerState = false;

void buzzerOn()
{
  digitalWrite(BUZZER_PIN, HIGH);
  buzzerState = true;
}


void buzzerOff()
{
  digitalWrite(BUZZER_PIN, LOW);
  buzzerState = false;
}


void beep(unsigned long duration)
{
  buzzerOn();
  delay(duration);
  buzzerOff();
}


// ============================================================
// LCD HELPER
// ============================================================

void lcdClearLine(int row)
{
  lcd.setCursor(0, row);
  lcd.print("                ");
}


void lcdMessage(String line1, String line2)
{
  lcd.clear();

  lcd.setCursor(0, 0);
  lcd.print(line1.substring(0, 16));

  lcd.setCursor(0, 1);
  lcd.print(line2.substring(0, 16));
}


// ============================================================
// SERVO CONTROL
// ============================================================

void releaseActuator()
{
  currentServoPosition = SERVO_RELEASED;

  brakeServo.write(currentServoPosition);

  Serial.println("ACTUATOR: RELEASED");
}


void limitedResistance()
{
  currentServoPosition = SERVO_LIMITED;

  brakeServo.write(currentServoPosition);

  Serial.println("ACTUATOR: LIMITED RESISTANCE");
}


// ============================================================
// MPU6050 BASIC FUNCTIONS
// ============================================================

void writeMPU(byte reg, byte value)
{
  Wire.beginTransmission(MPU6050_ADDR);

  Wire.write(reg);
  Wire.write(value);

  Wire.endTransmission();
}


int16_t readMPU16(byte reg)
{
  Wire.beginTransmission(MPU6050_ADDR);

  Wire.write(reg);

  Wire.endTransmission(false);

  Wire.requestFrom(MPU6050_ADDR, (uint8_t)2);

  int16_t value =
    (Wire.read() << 8) |
     Wire.read();

  return value;
}


void initializeMPU()
{
  writeMPU(MPU6050_PWR_MGMT_1, 0x00);

  delay(100);

  Serial.println("MPU6050 initialized.");
}


// ============================================================
// MPU6050 DATA
// ============================================================

struct SensorData
{
  float ax;
  float ay;
  float az;

  float gx;
  float gy;
  float gz;

  float accelerationMagnitude;
  float gyroMagnitude;

  float tiltAngle;
};


SensorData readMPU()
{
  SensorData data;

  int16_t rawAx = readMPU16(MPU6050_ACCEL_XOUT_H);
  int16_t rawAy = readMPU16(MPU6050_ACCEL_XOUT_H + 2);
  int16_t rawAz = readMPU16(MPU6050_ACCEL_XOUT_H + 4);

  int16_t rawGx = readMPU16(MPU6050_GYRO_XOUT_H);
  int16_t rawGy = readMPU16(MPU6050_GYRO_XOUT_H + 2);
  int16_t rawGz = readMPU16(MPU6050_GYRO_XOUT_H + 4);


  // Default MPU6050 ranges:
  // Accelerometer = +/-2g
  // Gyroscope = +/-250 deg/s

  data.ax = rawAx / 16384.0;
  data.ay = rawAy / 16384.0;
  data.az = rawAz / 16384.0;


  data.gx = rawGx / 131.0;
  data.gy = rawGy / 131.0;
  data.gz = rawGz / 131.0;


  // Total acceleration

  data.accelerationMagnitude =
    sqrt(
      data.ax * data.ax +
      data.ay * data.ay +
      data.az * data.az
    );


  // Total angular velocity

  data.gyroMagnitude =
    sqrt(
      data.gx * data.gx +
      data.gy * data.gy +
      data.gz * data.gz
    );


  // Basic tilt angle

  data.tiltAngle =
    atan2(
      sqrt(data.ax * data.ax + data.ay * data.ay),
      abs(data.az)
    ) * 180.0 / PI;


  return data;
}


// ============================================================
// ACCIDENT DETECTION
// ============================================================

void checkAccident()
{
  SensorData data = readMPU();


  bool strongImpact =
    data.accelerationMagnitude >= IMPACT_THRESHOLD;


  bool strongRotation =
    data.gyroMagnitude >= GYRO_THRESHOLD;


  bool severeTilt =
    data.tiltAngle >= TILT_THRESHOLD;


  // ----------------------------------------------------------
  // Accident detection uses multiple conditions.
  //
  // Impact alone does NOT automatically mean accident.
  // ----------------------------------------------------------

  if (
    (strongImpact && strongRotation) ||
    (strongImpact && severeTilt)
  )
  {
    if (!accidentDetected)
    {
      accidentDetected = true;

      Serial.println();
      Serial.println("!!!!!!!!!!!!!!!!!!!!!!!!");
      Serial.println("ACCIDENT DETECTED");
      Serial.println("!!!!!!!!!!!!!!!!!!!!!!!!");

      lcdMessage(
        "ACCIDENT ALERT",
        "ADMIN NOTIFIED"
      );

      // Alert only.
      // Do NOT automatically lock the wheel.

      beep(500);
      delay(200);
      beep(500);
    }
  }


  // Tilt can be recorded separately

  tiltDetected = severeTilt;


  // Debug information

  Serial.print("ACC: ");
  Serial.print(data.accelerationMagnitude, 2);

  Serial.print(" g | GYRO: ");
  Serial.print(data.gyroMagnitude, 1);

  Serial.print(" | TILT: ");
  Serial.println(data.tiltAngle, 1);
}


// ============================================================
// RENTAL TIMER
// ============================================================

unsigned long getRentalElapsed()
{
  if (!rentalActive)
    return 0;

  return millis() - rentalStartTime;
}


unsigned long getRentalRemaining()
{
  unsigned long elapsed = getRentalElapsed();

  if (elapsed >= RENTAL_DURATION)
    return 0;

  return RENTAL_DURATION - elapsed;
}


void startRental()
{
  rentalStartTime = millis();

  rentalActive = true;
  graceActive = false;
  rentalExpired = false;

  accidentDetected = false;

  releaseActuator();

  lcdMessage(
    "RENTAL STARTED",
    "30 MINUTES"
  );

  Serial.println();
  Serial.println("================================");
  Serial.println("RENTAL STARTED");
  Serial.println("================================");
}


void stopRental()
{
  rentalActive = false;
  graceActive = false;
  rentalExpired = false;

  releaseActuator();

  lcdMessage(
    "RENTAL STOPPED",
    "SYSTEM READY"
  );

  Serial.println("RENTAL STOPPED");
}


void updateRentalTimer()
{
  if (!rentalActive)
    return;


  unsigned long elapsed =
    millis() - rentalStartTime;


  // ----------------------------------------------------------
  // NORMAL RENTAL
  // ----------------------------------------------------------

  if (elapsed < RENTAL_DURATION)
  {
    unsigned long remaining =
      RENTAL_DURATION - elapsed;

    unsigned long totalSeconds =
      remaining / 1000;

    int minutes =
      totalSeconds / 60;

    int seconds =
      totalSeconds % 60;


    lcd.setCursor(0, 0);
    lcd.print("TIME LEFT       ");


    lcd.setCursor(0, 1);

    if (minutes < 10)
      lcd.print("0");

    lcd.print(minutes);

    lcd.print(":");

    if (seconds < 10)
      lcd.print("0");

    lcd.print(seconds);

    lcd.print("        ");


    return;
  }


  // ----------------------------------------------------------
  // RENTAL EXPIRED
  // ----------------------------------------------------------

  if (!rentalExpired)
  {
    rentalExpired = true;
    graceActive = true;

    Serial.println();
    Serial.println("RENTAL TIME EXPIRED");

    lcdMessage(
      "TIME EXPIRED",
      "5 MIN GRACE"
    );

    limitedResistance();

    beep(1000);
  }


  // ----------------------------------------------------------
  // GRACE PERIOD
  // ----------------------------------------------------------

  unsigned long graceElapsed =
    elapsed - RENTAL_DURATION;


  if (graceElapsed < GRACE_PERIOD)
  {
    unsigned long remaining =
      GRACE_PERIOD - graceElapsed;


    unsigned long totalSeconds =
      remaining / 1000;


    int minutes =
      totalSeconds / 60;


    int seconds =
      totalSeconds % 60;


    lcd.setCursor(0, 0);
    lcd.print("GRACE PERIOD    ");


    lcd.setCursor(0, 1);

    if (minutes < 10)
      lcd.print("0");

    lcd.print(minutes);

    lcd.print(":");

    if (seconds < 10)
      lcd.print("0");

    lcd.print(seconds);

    lcd.print("        ");


    // Periodic warning

    static unsigned long lastBeep = 0;

    if (millis() - lastBeep >= 5000)
    {
      lastBeep = millis();

      beep(200);
    }
  }

  // ----------------------------------------------------------
  // GRACE PERIOD FINISHED
  // ----------------------------------------------------------

  else
  {
    lcdMessage(
      "RENTAL OVER",
      "ADMIN ACTION"
    );

    limitedResistance();

    static unsigned long lastFinalBeep = 0;

    if (millis() - lastFinalBeep >= 3000)
    {
      lastFinalBeep = millis();

      beep(500);
    }
  }
}


// ============================================================
// EC20 CELLULAR FUNCTIONS
// ============================================================

void sendEC20Command(
  String command,
  unsigned long waitTime = 1000
)
{
  Serial.print("EC20 << ");
  Serial.println(command);

  EC20.println(command);

  unsigned long start =
    millis();


  while (millis() - start < waitTime)
  {
    while (EC20.available())
    {
      char c = EC20.read();

      Serial.write(c);
    }
  }


  Serial.println();
}


// ============================================================
// EC20 INITIALIZATION
// ============================================================

void initializeEC20()
{
  Serial.println();
  Serial.println("================================");
  Serial.println("INITIALIZING EC20");
  Serial.println("================================");


  delay(3000);


  sendEC20Command("AT", 1000);

  sendEC20Command("ATE0", 1000);

  sendEC20Command("ATI", 2000);

  sendEC20Command(
    "AT+CPIN?",
    2000
  );

  sendEC20Command(
    "AT+CSQ",
    2000
  );

  sendEC20Command(
    "AT+CEREG?",
    2000
  );


  Serial.println();
  Serial.println("EC20 initialization finished.");
}


// ============================================================
// SERIAL COMMANDS
// ============================================================

void processSerialCommands()
{
  if (!Serial.available())
    return;


  String command =
    Serial.readStringUntil('\n');


  command.trim();
  command.toUpperCase();


  // ----------------------------------------------------------
  // RENTAL
  // ----------------------------------------------------------

  if (command == "START")
  {
    startRental();
  }


  else if (command == "STOP")
  {
    stopRental();
  }


  // ----------------------------------------------------------
  // SERVO
  // ----------------------------------------------------------

  else if (command == "SERVO RELEASE")
  {
    releaseActuator();
  }


  else if (command == "SERVO LIMIT")
  {
    limitedResistance();
  }


  // ----------------------------------------------------------
  // ACCIDENT RESET
  // ----------------------------------------------------------

  else if (command == "RESET ACCIDENT")
  {
    accidentDetected = false;

    lcdMessage(
      "ACCIDENT RESET",
      "SYSTEM READY"
    );

    Serial.println("Accident status reset.");
  }


  // ----------------------------------------------------------
  // STATUS
  // ----------------------------------------------------------

  else if (command == "STATUS")
  {
    Serial.println();
    Serial.println("========== STATUS ==========");

    Serial.print("Rental active: ");
    Serial.println(rentalActive ? "YES" : "NO");

    Serial.print("Rental expired: ");
    Serial.println(rentalExpired ? "YES" : "NO");

    Serial.print("Accident detected: ");
    Serial.println(
      accidentDetected ? "YES" : "NO"
    );

    Serial.print("Tilt detected: ");
    Serial.println(
      tiltDetected ? "YES" : "NO"
    );

    Serial.print("Servo position: ");
    Serial.println(currentServoPosition);

    Serial.println("============================");
  }


  // ----------------------------------------------------------
  // EC20 COMMAND
  // ----------------------------------------------------------

  else if (command.startsWith("AT"))
  {
    EC20.println(command);

    delay(100);

    while (EC20.available())
    {
      Serial.write(
        EC20.read()
      );
    }
  }


  // ----------------------------------------------------------
  // HELP
  // ----------------------------------------------------------

  else if (command == "HELP")
  {
    Serial.println();
    Serial.println("AVAILABLE COMMANDS:");
    Serial.println("-------------------");
    Serial.println("START");
    Serial.println("STOP");
    Serial.println("SERVO RELEASE");
    Serial.println("SERVO LIMIT");
    Serial.println("RESET ACCIDENT");
    Serial.println("STATUS");
    Serial.println("AT");
    Serial.println("ATI");
    Serial.println("AT+CPIN?");
    Serial.println("AT+CSQ");
    Serial.println("AT+CEREG?");
    Serial.println();
  }


  else
  {
    Serial.println(
      "Unknown command. Type HELP."
    );
  }
}


// ============================================================
// SETUP
// ============================================================

void setup()
{
  Serial.begin(115200);

  delay(1000);


  Serial.println();
  Serial.println("================================");
  Serial.println("       PEDALYA SYSTEM");
  Serial.println("================================");


  // ----------------------------------------------------------
  // I2C
  // ----------------------------------------------------------

  Wire.begin(
    SDA_PIN,
    SCL_PIN
  );


  // ----------------------------------------------------------
  // LCD
  // ----------------------------------------------------------

  lcd.init();

  lcd.backlight();

  lcdMessage(
    "PEDALYA",
    "INITIALIZING..."
  );


  // ----------------------------------------------------------
  // BUZZER
  // ----------------------------------------------------------

  pinMode(
    BUZZER_PIN,
    OUTPUT
  );

  buzzerOff();


  // ----------------------------------------------------------
  // SERVO
  // ----------------------------------------------------------

  brakeServo.setPeriodHertz(50);

  brakeServo.attach(
    SERVO_PIN,
    500,
    2400
  );

  releaseActuator();


  // ----------------------------------------------------------
  // MPU6050
  // ----------------------------------------------------------

  initializeMPU();


  // ----------------------------------------------------------
  // EC20
  // ----------------------------------------------------------

  EC20.begin(
    115200,
    SERIAL_8N1,
    EC20_RX,
    EC20_TX
  );


  initializeEC20();


  // ----------------------------------------------------------
  // READY
  // ----------------------------------------------------------

  lcdMessage(
    "PEDALYA READY",
    "TYPE START"
  );


  Serial.println();
  Serial.println("================================");
  Serial.println("SYSTEM READY");
  Serial.println("================================");

  Serial.println();
  Serial.println("Type HELP for commands.");
  Serial.println("Type START to begin a rental.");
}


// ============================================================
// LOOP
// ============================================================

void loop()
{
  // Process user commands
  processSerialCommands();


  // Update rental timer
  updateRentalTimer();


  // Check MPU6050
  static unsigned long lastSensorCheck = 0;

  if (millis() - lastSensorCheck >= 500)
  {
    lastSensorCheck = millis();

    checkAccident();
  }


  // Small delay
  delay(10);
}
