#include <Wire.h>

/*
 * LCD only. Flash this when the screen misbehaves and nothing else does.
 *
 * Register-level HD44780 over I2C, no library, so a failure here points at the
 * wiring or the backpack rather than at the rental firmware.
 *
 *   SDA -> GPIO21, SCL -> GPIO22, VCC -> 5V, GND -> GND
 *
 * If the backlight lights but no text appears, the backpack is usually 0x3F
 * rather than 0x27. The scan in setup() prints the address it actually found.
 */

#define SDA_PIN 21
#define SCL_PIN 22
#define LCD_ADDR 0x27
#define LCD_COLUMNS 16

void lcdWriteByte(uint8_t data) {
    Wire.beginTransmission(LCD_ADDR);
    Wire.write(data);
    Wire.endTransmission();
}

void lcdPulse(uint8_t data) {
    lcdWriteByte(data | 0x04);
    delayMicroseconds(1);

    lcdWriteByte(data & ~0x04);
    delayMicroseconds(50);
}

void lcdSend(uint8_t value, uint8_t mode) {
    const uint8_t high = value & 0xF0;
    const uint8_t low = (value << 4) & 0xF0;

    lcdPulse(high | mode | 0x08);
    lcdPulse(low | mode | 0x08);
}

void lcdCommand(uint8_t command) {
    lcdSend(command, 0);
}

void lcdChar(char c) {
    lcdSend(c, 0x01);
}

void lcdClear() {
    lcdCommand(0x01);
    delay(2);
}

void lcdSetCursor(uint8_t col, uint8_t row) {
    const uint8_t offsets[] = {0x00, 0x40};

    lcdCommand(0x80 | (col + offsets[row]));
}

void lcdPrint(const char *text) {
    while (*text) {
        lcdChar(*text++);
    }
}

void lcdInit() {
    delay(50);

    // Backlight ON
    lcdWriteByte(0x08);

    // Initialize LCD in 8-bit mode
    lcdPulse(0x30 | 0x08);
    delay(5);

    lcdPulse(0x30 | 0x08);
    delayMicroseconds(150);

    lcdPulse(0x30 | 0x08);

    // Switch to 4-bit mode
    lcdPulse(0x20 | 0x08);

    // 4-bit, 2-line, 5x8
    lcdCommand(0x28);

    // Display OFF
    lcdCommand(0x08);

    // Clear
    lcdClear();

    // Entry mode
    lcdCommand(0x06);

    // Display ON
    lcdCommand(0x0C);
}

/** Every address that answers, so a wrong LCD_ADDR is obvious from the log. */
void scanI2c() {
    Serial.printf("[lcd] scanning SDA=GPIO%d SCL=GPIO%d\n", SDA_PIN, SCL_PIN);
    uint8_t found = 0;

    for (uint8_t address = 0x08; address <= 0x77; address++) {
        Wire.beginTransmission(address);
        if (Wire.endTransmission() == 0) {
            Serial.printf("[lcd]   device at 0x%02X\n", address);
            found++;
        }
    }

    if (found == 0) {
        Serial.println("[lcd] nothing answered. Check VCC, GND, SDA and SCL.");
    }
}

void setup() {
    Serial.begin(115200);

    Wire.begin(SDA_PIN, SCL_PIN);

    Serial.println("LCD TEST");
    scanI2c();
    Serial.printf("[lcd] driving 0x%02X\n", LCD_ADDR);

    lcdInit();

    lcdSetCursor(0, 0);
    lcdPrint("PEDALYA");

    lcdSetCursor(0, 1);
    lcdPrint("LCD TEST OK");

    Serial.println("[lcd] wrote PEDALYA / LCD TEST OK");
}

void loop() {
}