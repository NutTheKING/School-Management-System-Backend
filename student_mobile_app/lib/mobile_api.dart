import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class MobileApiException implements Exception {
  final String message;
  const MobileApiException(this.message);
  @override
  String toString() => message;
}

class MobileApi {
  static const String _configuredUrl = String.fromEnvironment('API_BASE_URL');
  static String get baseUrl => _configuredUrl.isNotEmpty
      ? _configuredUrl
      : kIsWeb
          ? 'http://127.0.0.1/system_cs/api.php'
          : 'http://10.0.2.2/system_cs/api.php';
  static const _tokenKey = 'wg_mobile_token';

  Future<String?> savedToken() async =>
      (await SharedPreferences.getInstance()).getString(_tokenKey);

  Future<Map<String, dynamic>> login(String studentId, String password) async {
    final result = await _request('mobile-login', method: 'POST', body: {
      'student_id': studentId.trim(),
      'password': password,
    });
    final token = result['token']?.toString() ?? '';
    if (token.isEmpty)
      throw const MobileApiException('Login token was not returned');
    await (await SharedPreferences.getInstance()).setString(_tokenKey, token);
    return dashboard(token);
  }

  Future<Map<String, dynamic>> dashboard([String? accessToken]) async {
    final token = accessToken ?? await savedToken();
    if (token == null || token.isEmpty) {
      throw const MobileApiException('Please sign in');
    }
    return _request('mobile-dashboard', token: token);
  }

  Future<Map<String, dynamic>> scanAttendance(String payload) async {
    final token = await savedToken();
    if (token == null || token.isEmpty)
      throw const MobileApiException('Please sign in');
    return _request('mobile-attendance-scan',
        method: 'POST', token: token, body: {'payload': payload});
  }

  Future<Map<String, dynamic>> prepareInvoicePayment(String invoiceId) async {
    final token = await savedToken();
    if (token == null || token.isEmpty)
      throw const MobileApiException('Please sign in');
    return _request('mobile-pay-invoice', method: 'POST', token: token, body: {
      'operation': 'prepare',
      'invoice_id': invoiceId,
    });
  }

  Future<Map<String, dynamic>> completeInvoicePayment(String invoiceId) async {
    final token = await savedToken();
    if (token == null || token.isEmpty)
      throw const MobileApiException('Please sign in');
    return _request('mobile-pay-invoice', method: 'POST', token: token, body: {
      'operation': 'complete',
      'invoice_id': invoiceId,
    });
  }

  Future<Map<String, dynamic>> uploadPaymentProof({
    required String invoiceId,
    required String reference,
    required String receiptImage,
  }) async {
    final token = await savedToken();
    if (token == null || token.isEmpty)
      throw const MobileApiException('Please sign in');
    return _request('mobile-payment-proof',
        method: 'POST',
        token: token,
        body: {
          'invoice_id': invoiceId,
          'reference': reference,
          'receipt_image': receiptImage,
        });
  }

  Future<void> logout() async {
    final token = await savedToken();
    if (token != null) {
      try {
        await _request('mobile-logout', method: 'POST', token: token);
      } catch (_) {}
    }
    await (await SharedPreferences.getInstance()).remove(_tokenKey);
  }

  Future<Map<String, dynamic>> _request(
    String resource, {
    String method = 'GET',
    String? token,
    Map<String, dynamic>? body,
  }) async {
    final uri =
        Uri.parse('$baseUrl?resource=${Uri.encodeQueryComponent(resource)}');
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (token != null) headers['Authorization'] = 'Bearer $token';
    late http.Response response;
    try {
      response = method == 'POST'
          ? await http.post(uri, headers: headers, body: jsonEncode(body ?? {}))
          : await http.get(uri, headers: headers);
    } catch (_) {
      throw MobileApiException('Cannot connect to $baseUrl');
    }
    Map<String, dynamic> payload;
    try {
      payload = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      throw const MobileApiException('The server returned an invalid response');
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw MobileApiException(
          payload['error']?.toString() ?? 'Request failed');
    }
    return payload;
  }
}
