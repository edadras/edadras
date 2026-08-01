import 'package:flutter/material.dart';

/// The GymFlow AI look: bright green on very dark grey, frosted glass over a
/// blurred colour field. Both apps build their whole surface from this.
abstract final class AppTheme {
  static const brand = Color(0xFF5EF38C);
  static const brandSoft = Color(0xFF8FF8B6);
  static const brandDeep = Color(0xFF2FD96B);

  static const ink900 = Color(0xFF0D0F12);
  static const ink800 = Color(0xFF14171C);
  static const ink700 = Color(0xFF1C2027);
  static const ink400 = Color(0xFF6B7280);
  static const ink200 = Color(0xFFCBD2DC);

  static const danger = Color(0xFFF43F5E);
  static const warning = Color(0xFFFBBF24);

  static const glassRadius = 22.0;

  /// Glass panels need a translucent, not opaque, fill.
  static Color glassFill(double opacity) => Colors.white.withValues(alpha: opacity);

  /// A club can brand the app with its own colour; this parses the hex the
  /// API stores and falls back to the platform green.
  static Color parseBrandColor(String? hex) {
    if (hex == null) return brand;

    final cleaned = hex.replaceFirst('#', '');
    final value = int.tryParse(cleaned.length == 6 ? 'FF$cleaned' : cleaned, radix: 16);

    return value == null ? brand : Color(value);
  }

  static ThemeData build({Color? accent, String fontFamily = 'Vazirmatn'}) {
    final seed = accent ?? brand;

    final scheme = ColorScheme.fromSeed(
      seedColor: seed,
      brightness: Brightness.dark,
      primary: seed,
      onPrimary: ink900,
      surface: ink800,
      onSurface: Colors.white,
      error: danger,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: ink900,
      fontFamily: fontFamily,
      splashFactory: InkSparkle.splashFactory,
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: Colors.white,
        ),
      ),
      textTheme: const TextTheme(
        headlineSmall: TextStyle(fontWeight: FontWeight.w800, color: Colors.white),
        titleMedium: TextStyle(fontWeight: FontWeight.w700, color: Colors.white),
        bodyMedium: TextStyle(color: Colors.white, height: 1.5),
        bodySmall: TextStyle(color: ink200, height: 1.4),
        labelSmall: TextStyle(color: ink400, letterSpacing: 0.3),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: ink800.withValues(alpha: 0.7),
        hintStyle: const TextStyle(color: ink400),
        labelStyle: const TextStyle(color: ink200),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: Colors.white.withValues(alpha: 0.12)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: Colors.white.withValues(alpha: 0.12)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: seed.withValues(alpha: 0.7), width: 1.5),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: danger),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: seed,
          foregroundColor: ink900,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 16),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: Colors.white,
          side: BorderSide(color: Colors.white.withValues(alpha: 0.14)),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 15),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: ink800.withValues(alpha: 0.85),
        indicatorColor: seed.withValues(alpha: 0.18),
        surfaceTintColor: Colors.transparent,
        labelTextStyle: WidgetStatePropertyAll(
          TextStyle(fontSize: 11, color: ink200, fontWeight: FontWeight.w600),
        ),
      ),
      dividerTheme: DividerThemeData(
        color: Colors.white.withValues(alpha: 0.07),
        space: 1,
        thickness: 1,
      ),
      // Chips default to a light Material palette, which reads as a mistake
      // against the dark glass, so they are dressed to match.
      chipTheme: ChipThemeData(
        backgroundColor: Colors.white.withValues(alpha: 0.06),
        labelStyle: const TextStyle(color: ink200, fontSize: 13),
        side: BorderSide(color: Colors.white.withValues(alpha: 0.10)),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: ink700,
        contentTextStyle: const TextStyle(color: Colors.white),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    );
  }
}
