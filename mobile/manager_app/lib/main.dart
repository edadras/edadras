import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';

import 'screens/home_shell.dart';
import 'screens/login_screen.dart';

void main() {
  // --dart-define=API_URL=https://api.your-domain.com/api/v1 in release.
  const apiUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  final api = ApiClient(baseUrl: apiUrl);

  runApp(ManagerApp(api: api));
}

class ManagerApp extends StatefulWidget {
  const ManagerApp({super.key, required this.api});

  final ApiClient api;

  @override
  State<ManagerApp> createState() => _ManagerAppState();
}

class _ManagerAppState extends State<ManagerApp> {
  late final SessionController _session = SessionController(widget.api);
  late final Translations _translations = Translations(widget.api);

  @override
  void initState() {
    super.initState();
    _boot();
  }

  /// The string table and the stored session load together, so the first
  /// frame after the splash is already in the right language.
  Future<void> _boot() async {
    await _session.restore();

    try {
      await _translations.loadLocales();
      await _translations.load(widget.api.locale);
    } catch (_) {
      // Offline: the interface falls back to its keys until the API is back.
    }

    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return SessionScope(
      session: _session,
      child: TranslationsScope(
        translations: _translations,
        child: AnimatedBuilder(
          animation: Listenable.merge([_session, _translations]),
          builder: (context, _) {
            final accent = AppTheme.parseBrandColor(_session.club?.brandColor);

            return MaterialApp(
              title: 'GymFlow AI',
              debugShowCheckedModeBanner: false,
              theme: AppTheme.build(accent: accent),
              builder: (context, child) => Directionality(
                textDirection: _translations.direction,
                child: child ?? const SizedBox.shrink(),
              ),
              home: _session.isRestoring
                  ? const _SplashScreen()
                  : _session.isAuthenticated
                      ? const HomeShell()
                      : const LoginScreen(),
            );
          },
        ),
      ),
    );
  }
}

class _SplashScreen extends StatelessWidget {
  const _SplashScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: GradientBackdrop(
        child: Center(child: CircularProgressIndicator(color: AppTheme.brand)),
      ),
    );
  }
}
