"""OpenAI-compatible inference client.

The SDIP harness talks to the model layer via an OpenAI-compatible HTTP
endpoint (the de facto standard exposed by Ollama, vLLM, llama.cpp,
LM Studio, OpenAI itself, and most other inference servers).

This module is a thin wrapper over httpx that emits Chat Completions
requests and yields streamed tokens. Synchronous and async surfaces.
"""
from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any, AsyncIterator, Iterator

# httpx is imported lazily inside methods so this module can be imported
# (for type hints and ChatMessage) without the dependency installed.


class ModelError(Exception):
    """Raised on model-layer transport or response errors."""


@dataclass
class ChatMessage:
    role: str  # "system", "user", "assistant"
    content: str

    def to_dict(self) -> dict[str, str]:
        return {"role": self.role, "content": self.content}


@dataclass
class ChatChoice:
    message: ChatMessage
    finish_reason: str | None


@dataclass
class ChatResponse:
    model: str
    choices: list[ChatChoice]
    usage: dict[str, int]


class Model:
    """OpenAI-compatible chat client.

    Args:
        endpoint: Base URL of the OpenAI-compatible server. E.g.,
            "http://localhost:11434/v1" for Ollama, or
            "http://localhost:8000/v1" for vLLM.
        model_name: The model identifier the server uses. E.g.,
            "qwen2.5:72b-instruct-abliterated" for Ollama.
        api_key: Optional API key. Not required for local servers.
        timeout: Request timeout in seconds.
    """

    def __init__(
        self,
        endpoint: str,
        model_name: str,
        api_key: str | None = None,
        timeout: float = 180.0,
    ):
        self.endpoint = endpoint.rstrip("/")
        self.model_name = model_name
        self.api_key = api_key
        self.timeout = timeout

    def _headers(self) -> dict[str, str]:
        h = {"Content-Type": "application/json", "Accept": "application/json"}
        if self.api_key:
            h["Authorization"] = f"Bearer {self.api_key}"
        return h

    def chat(
        self,
        messages: list[ChatMessage],
        max_tokens: int = 2048,
        temperature: float | None = None,
    ) -> ChatResponse:
        """Send a synchronous chat completion request and return the full response."""
        import httpx

        body: dict[str, Any] = {
            "model": self.model_name,
            "messages": [m.to_dict() for m in messages],
            "max_tokens": max_tokens,
            "stream": False,
        }
        if temperature is not None:
            body["temperature"] = temperature

        try:
            r = httpx.post(
                f"{self.endpoint}/chat/completions",
                json=body,
                headers=self._headers(),
                timeout=self.timeout,
            )
        except httpx.HTTPError as e:
            raise ModelError(f"chat request transport error: {e}") from e

        if r.status_code != 200:
            raise ModelError(f"chat request failed {r.status_code}: {r.text[:500]}")

        data = r.json()
        return _parse_chat_response(data)

    def chat_stream(
        self,
        messages: list[ChatMessage],
        max_tokens: int = 2048,
        temperature: float | None = None,
    ) -> Iterator[str]:
        """Send a streaming chat completion request and yield tokens as they arrive."""
        import httpx

        body: dict[str, Any] = {
            "model": self.model_name,
            "messages": [m.to_dict() for m in messages],
            "max_tokens": max_tokens,
            "stream": True,
        }
        if temperature is not None:
            body["temperature"] = temperature

        try:
            with httpx.stream(
                "POST",
                f"{self.endpoint}/chat/completions",
                json=body,
                headers=self._headers(),
                timeout=self.timeout,
            ) as r:
                if r.status_code != 200:
                    raise ModelError(f"chat stream failed {r.status_code}: {r.read()[:500].decode()}")
                for line in r.iter_lines():
                    if not line or not line.startswith("data:"):
                        continue
                    payload = line[5:].strip()
                    if payload == "[DONE]":
                        break
                    try:
                        chunk = json.loads(payload)
                    except json.JSONDecodeError:
                        continue
                    delta = chunk.get("choices", [{}])[0].get("delta", {})
                    token = delta.get("content")
                    if token:
                        yield token
        except httpx.HTTPError as e:
            raise ModelError(f"chat stream transport error: {e}") from e

    async def chat_stream_async(
        self,
        messages: list[ChatMessage],
        max_tokens: int = 2048,
        temperature: float | None = None,
    ) -> AsyncIterator[str]:
        """Async streaming chat completion."""
        import httpx

        body: dict[str, Any] = {
            "model": self.model_name,
            "messages": [m.to_dict() for m in messages],
            "max_tokens": max_tokens,
            "stream": True,
        }
        if temperature is not None:
            body["temperature"] = temperature

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            try:
                async with client.stream(
                    "POST",
                    f"{self.endpoint}/chat/completions",
                    json=body,
                    headers=self._headers(),
                ) as r:
                    if r.status_code != 200:
                        body_text = (await r.aread())[:500].decode()
                        raise ModelError(f"chat stream failed {r.status_code}: {body_text}")
                    async for line in r.aiter_lines():
                        if not line or not line.startswith("data:"):
                            continue
                        payload = line[5:].strip()
                        if payload == "[DONE]":
                            break
                        try:
                            chunk = json.loads(payload)
                        except json.JSONDecodeError:
                            continue
                        delta = chunk.get("choices", [{}])[0].get("delta", {})
                        token = delta.get("content")
                        if token:
                            yield token
            except httpx.HTTPError as e:
                raise ModelError(f"async chat stream transport error: {e}") from e


def _parse_chat_response(data: dict[str, Any]) -> ChatResponse:
    choices = []
    for c in data.get("choices", []):
        msg = c.get("message", {})
        choices.append(
            ChatChoice(
                message=ChatMessage(role=msg.get("role", "assistant"), content=msg.get("content", "")),
                finish_reason=c.get("finish_reason"),
            )
        )
    return ChatResponse(
        model=data.get("model", ""),
        choices=choices,
        usage=data.get("usage", {}),
    )
