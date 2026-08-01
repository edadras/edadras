import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:nfc_manager/nfc_manager.dart';

/// The gate. A member's badge arrives as a QR scan, an NFC card, or a code
/// typed by hand; the API decides, and the screen answers with a green or
/// red card the receptionist can read across the desk.
class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

enum _InputMode { camera, nfc, manual }

class _ScannerScreenState extends State<ScannerScreen> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.qrCode],
  );
  final TextEditingController _manualController = TextEditingController();

  _InputMode _mode = _InputMode.camera;
  CheckInResult? _result;
  bool _busy = false;
  bool _checkingOut = false;
  bool _nfcListening = false;
  DateTime? _lastScan;

  @override
  void dispose() {
    _controller.dispose();
    _manualController.dispose();
    _stopNfc();
    super.dispose();
  }

  /// A badge held up to the lens fires repeatedly; one scan per two seconds
  /// is enough, and it stops a nervous hand from double charging a quota.
  bool get _isCoolingDown =>
      _lastScan != null && DateTime.now().difference(_lastScan!).inMilliseconds < 2000;

  Future<void> _onDetect(BarcodeCapture capture) async {
    final token = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;

    if (token == null || _busy || _isCoolingDown) return;

    _lastScan = DateTime.now();
    await _submit(qrToken: token);
  }

  Future<void> _startNfc() async {
    if (!await NfcManager.instance.isAvailable()) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              TranslationsScope.of(context).t('checkin.nfc_unavailable', 'NFC is not available on this device.'),
            ),
          ),
        );
        setState(() => _mode = _InputMode.camera);
      }

      return;
    }

    setState(() => _nfcListening = true);

    NfcManager.instance.startSession(
      onDiscovered: (NfcTag tag) async {
        final uid = _readTagId(tag);

        if (uid == null || _busy || _isCoolingDown) return;

        _lastScan = DateTime.now();
        await _submit(nfcUid: uid);
      },
    );
  }

  Future<void> _stopNfc() async {
    if (!_nfcListening) return;

    _nfcListening = false;

    try {
      await NfcManager.instance.stopSession();
    } catch (_) {
      // Stopping a session that already ended is not worth surfacing.
    }
  }

  /// Card serials sit under a different key per tag technology, so try the
  /// ones a wristband or membership card actually uses.
  String? _readTagId(NfcTag tag) {
    for (final key in ['nfca', 'nfcb', 'nfcf', 'nfcv', 'mifare', 'iso7816', 'ndef']) {
      final technology = tag.data[key];

      if (technology is! Map) continue;

      final identifier = technology['identifier'];

      if (identifier is List) {
        return identifier
            .map((byte) => (byte as int).toRadixString(16).padLeft(2, '0'))
            .join()
            .toUpperCase();
      }
    }

    return null;
  }

  Future<void> _submit({String? qrToken, String? nfcUid}) async {
    final api = SessionScope.of(context).api;
    final t = TranslationsScope.of(context);

    setState(() {
      _busy = true;
      _result = null;
    });

    try {
      final path = _checkingOut ? '/attendance/check-out' : '/attendance/check-in';
      final response = await api.post(path, body: {
        if (qrToken != null) 'qr_token': qrToken,
        if (nfcUid != null) 'nfc_uid': nfcUid,
        'method': nfcUid != null ? 'nfc' : 'qr',
      });

      setState(() {
        _result = CheckInResult.fromJson(
          (response as Map).cast<String, dynamic>(),
          allowed: true,
        );
      });
    } on ApiException catch (error) {
      setState(() {
        _result = CheckInResult.refused(
          error.message.isEmpty ? t.t('checkin.unknown_code', 'Unknown code.') : error.message,
          reason: error.reason,
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _changeMode(_InputMode mode) async {
    if (mode == _mode) return;

    await _stopNfc();
    setState(() => _mode = mode);

    if (mode == _InputMode.nfc) await _startNfc();
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  t.t('nav.check_in', 'Check-in'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
              ),
              // One toggle instead of two buttons: the desk is either taking
              // people in or letting them out.
              SegmentedButton<bool>(
                segments: [
                  ButtonSegment(value: false, label: Text(t.t('checkin.enter', 'In'))),
                  ButtonSegment(value: true, label: Text(t.t('checkin.exit', 'Out'))),
                ],
                selected: {_checkingOut},
                onSelectionChanged: (value) => setState(() => _checkingOut = value.first),
              ),
            ],
          ),
        ),

        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: SegmentedButton<_InputMode>(
            segments: [
              ButtonSegment(
                value: _InputMode.camera,
                icon: const Icon(Icons.qr_code_scanner, size: 18),
                label: Text(t.t('checkin.mode_qr', 'QR')),
              ),
              ButtonSegment(
                value: _InputMode.nfc,
                icon: const Icon(Icons.nfc, size: 18),
                label: Text(t.t('checkin.mode_nfc', 'NFC')),
              ),
              ButtonSegment(
                value: _InputMode.manual,
                icon: const Icon(Icons.keyboard, size: 18),
                label: Text(t.t('checkin.mode_manual', 'Code')),
              ),
            ],
            selected: {_mode},
            onSelectionChanged: (value) => _changeMode(value.first),
          ),
        ),

        Expanded(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: switch (_mode) {
              _InputMode.camera => ClipRRect(
                  borderRadius: BorderRadius.circular(AppTheme.glassRadius),
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      MobileScanner(controller: _controller, onDetect: _onDetect),
                      const _ScannerReticle(),
                      if (_busy)
                        const ColoredBox(
                          color: Colors.black45,
                          child: Center(
                            child: CircularProgressIndicator(color: AppTheme.brand),
                          ),
                        ),
                    ],
                  ),
                ),
              _InputMode.nfc => _NfcPrompt(listening: _nfcListening, busy: _busy),
              _InputMode.manual => _ManualEntry(
                  controller: _manualController,
                  busy: _busy,
                  onSubmit: () {
                    final token = _manualController.text.trim();

                    if (token.isEmpty) return;

                    _manualController.clear();
                    _submit(qrToken: token);
                  },
                ),
            },
          ),
        ),

        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
          child: _ResultCard(result: _result),
        ),
      ],
    );
  }
}

/// The corner brackets that tell the eye where to hold the badge.
class _ScannerReticle extends StatelessWidget {
  const _ScannerReticle();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Center(
        child: Container(
          width: 220,
          height: 220,
          decoration: BoxDecoration(
            border: Border.all(color: AppTheme.brand.withValues(alpha: 0.85), width: 3),
            borderRadius: BorderRadius.circular(28),
          ),
        ),
      ),
    );
  }
}

class _NfcPrompt extends StatelessWidget {
  const _NfcPrompt({required this.listening, required this.busy});

  final bool listening;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return GlassCard(
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.nfc,
              size: 64,
              color: listening ? AppTheme.brand : AppTheme.ink400,
            ),
            const SizedBox(height: 18),
            Text(
              busy
                  ? '…'
                  : t.t('checkin.nfc_hint', 'Hold the card or wristband to the back of the phone'),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}

class _ManualEntry extends StatelessWidget {
  const _ManualEntry({
    required this.controller,
    required this.busy,
    required this.onSubmit,
  });

  final TextEditingController controller;
  final bool busy;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return GlassCard(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          TextField(
            controller: controller,
            autofocus: true,
            onSubmitted: (_) => onSubmit(),
            decoration: InputDecoration(
              labelText: t.t('checkin.code_placeholder', 'Member code'),
              prefixIcon: const Icon(Icons.badge_outlined, color: AppTheme.ink400),
            ),
          ),
          const SizedBox(height: 16),
          BrandButton(
            label: t.t('checkin.enter', 'Enter'),
            loading: busy,
            onPressed: onSubmit,
          ),
        ],
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard({required this.result});

  final CheckInResult? result;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    if (result == null) {
      return GlassCard(
        child: Row(
          children: [
            const Icon(Icons.qr_code_2, color: AppTheme.ink400),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                t.t('checkin.scan_hint', 'Hold a member badge up to the camera'),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        ),
      );
    }

    final allowed = result!.allowed;
    final colour = allowed ? AppTheme.brand : AppTheme.danger;

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 260),
      child: GlassCard(
        key: ValueKey(result),
        strong: true,
        borderColor: colour.withValues(alpha: 0.55),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 52,
              height: 52,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: colour.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Icon(
                allowed ? Icons.check_rounded : Icons.block_rounded,
                color: colour,
                size: 30,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    result!.message,
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: colour,
                    ),
                  ),
                  if (result!.member != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      '${result!.member!.fullName} · #${result!.member!.code}',
                      style: const TextStyle(color: Colors.white, fontSize: 14),
                    ),
                  ],
                  if (allowed) ...[
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      children: [
                        if (result!.remainingSessions != null)
                          _Pill(
                            label:
                                '${t.t('checkin.sessions_left', 'Sessions')}: ${result!.remainingSessions}',
                          ),
                        if (result!.daysRemaining != null)
                          _Pill(
                            label:
                                '${t.t('checkin.days_left', 'Days')}: ${result!.daysRemaining}',
                          ),
                      ],
                    ),
                  ],
                  // A refusal the desk can fix on the spot deserves a nudge.
                  if (result!.isRenewable) ...[
                    const SizedBox(height: 8),
                    Text(
                      t.t('checkin.offer_renewal', 'Offer a renewal at the desk.'),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(label, style: const TextStyle(fontSize: 12, color: AppTheme.ink200)),
    );
  }
}
